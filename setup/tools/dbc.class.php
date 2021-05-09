<?php

/*
    DBC::read - PHP function for loading DBC file into array
    This file is a part of AoWoW project.
    Copyright (C) 2009-2010  Mix <ru-mangos.ru>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if (!defined('AOWOW_REVISION'))
    die('illegal access');

if (!CLI)
    die('not in cli mode');

class DBC
{
    private static $_formats;

    private static $_fields;

    private $isGameTable = false;
    private $localized   = false;
    private $tempTable   = true;
    private $tableName   = '';

    private $dataBuffer  = [];
    private $bufferSize  = 500;

    private $fileRefs    = [];

    public $error  = true;
    public $fields = [];
    public $format = '';
    public $file   = '';

    public static function init(array $config) {
        $schema = require_once("setup/tools/dbc/dbc.${config['client']}.php");
        
        self::$_fields = $_fields;
        self::$_formats = $_formats;
    }

    public function __construct($file, array $config, $opts = [])
    {
        $file = strtolower($file);
        if (empty(self::$_fields[$file]) || empty(self::$_formats[$file]))
        {
            CLI::write('no structure known for '.$file.'.dbc, aborting.', CLI::LOG_ERROR);
            return;
        }

        $this->config    = $config;
        $this->fields    = explode(',', self::$_fields[$file]);
        $this->format    = self::$_formats[$file];
        $this->file      = $file;
        $this->localized = !!strstr($this->format, 'sxsssxsxsxxxxxxxx');

        if (count($this->fields) != strlen(str_ireplace('x', '', $this->format)))
        {
            CLI::write('known field types ['.count($this->fields).'] and names ['.strlen(str_ireplace('x', '', $this->format)).'] do not match for '.$file.'.dbc, aborting.', CLI::LOG_ERROR);
            return;
        }

        if (is_bool($opts['temporary']))
            $this->tempTable = $opts['temporary'];

        if (!empty($opts['tableName']))
            $this->tableName = $opts['tableName'];
        else
            $this->tableName = 'dbc_'.$file;

        // gameTable-DBCs don't have an index and are accessed through value order
        // allas, you cannot do this with mysql, so we add a 'virtual' index
        $this->isGameTable = $this->format == 'f' && substr($file, 0, 2) == 'gt';

        $foundMask = 0x0;
        foreach (CLISetup::$expectedPaths as $locStr => $locId)
        {
            if (!in_array($locId, CLISetup::$localeIds))
                continue;

            if ($foundMask & (1 << $locId))
                continue;

            $fullPath = CLI::nicePath($this->file.'.dbc', CLISetup::$srcDir, $locStr, $this->config['client'], 'DBFilesClient');
            if (!CLISetup::fileExists($fullPath))
                continue;

            $this->curFile = $fullPath;
            if ($this->validateFile($locId))
                $foundMask |= (1 << $locId);
        }

        if (!$this->fileRefs)
        {
            CLI::write('no suitable files found for '.$file.'.dbc, aborting.', CLI::LOG_ERROR);
            return;
        }

        // check if DBCs are identical
        $headers = array_column($this->fileRefs, 2);
        $x = array_unique(array_column($headers, 'recordCount'));
        if (count($x) != 1)
        {
            CLI::write('some DBCs have differenct record counts ('.implode(', ', $x).' respectively). cannot merge!', CLI::LOG_ERROR);
            return;
        }
        $x = array_unique(array_column($headers, 'fieldCount'));
        if (count($x) != 1)
        {
            CLI::write('some DBCs have differenct field counts ('.implode(', ', $x).' respectively). cannot merge!', CLI::LOG_ERROR);
            return;
        }
        $x = array_unique(array_column($headers, 'recordSize'));
        if (count($x) != 1)
        {
            CLI::write('some DBCs have differenct record sizes ('.implode(', ', $x).' respectively). cannot merge!', CLI::LOG_ERROR);
            return;
        }

        $this->error = false;
    }

    public function readFile()
    {
        if (!$this->file || $this->error)
            return [];

        $this->createTable();

        CLI::write(' - reading '.($this->localized ? 'and merging ' : '').$this->file.'.dbc for locales '.implode(', ', array_keys($this->fileRefs)));

        if (!$this->read())
        {
            CLI::write(' - DBC::read() returned with error', CLI::LOG_ERROR);
            return false;
        }

        return true;
    }

    private function endClean()
    {
        foreach ($this->fileRefs as &$ref)
            fclose($ref[0]);

        $this->dataBuffer = null;
    }

    private function readHeader(&$handle = null)
    {
        if (!is_resource($handle))
            $handle = fopen($this->curFile, 'rb');

        if (!$handle)
            return false;

        if (fread($handle, 4) != 'WDBC')
        {
            CLI::write('file '.$this->curFile.' has incorrect magic bytes', CLI::LOG_ERROR);
            fclose($handle);
            return false;
        }

        return unpack('VrecordCount/VfieldCount/VrecordSize/VstringSize', fread($handle, 16));
    }

    private function validateFile($locId)
    {
        $filesize = filesize($this->curFile);
        if ($filesize < 20)
        {
            CLI::write('file '.$this->curFile.' is too small for a DBC file', CLI::LOG_ERROR);
            return false;
        }

        $header = $this->readHeader($handle);
        if (!$header)
        {
            CLI::write('cannot open file '.$this->curFile, CLI::LOG_ERROR);
            return false;
        }

        // Different debug checks to be sure, that file was opened correctly
        $debugStr = '(recordCount='.$header['recordCount'].
                    ' fieldCount=' .$header['fieldCount'] .
                    ' recordSize=' .$header['recordSize'] .
                    ' stringSize=' .$header['stringSize'] .')';

        if ($header['recordCount'] * $header['recordSize'] + $header['stringSize'] + 20 != $filesize)
        {
            CLI::write('file '.$this->curFile.' has incorrect size '.$filesize.': '.$debugStr, CLI::LOG_ERROR);
            fclose($handle);
            return false;
        }

        if ($header['fieldCount'] != strlen($this->format))
        {
            CLI::write('incorrect format string ('.$this->format.') specified for file '.$this->curFile.' fieldCount='.$header['fieldCount'], CLI::LOG_ERROR);
            fclose($handle);
            return false;
        }

        $this->fileRefs[$locId] = [$handle, $this->curFile, $header];

        return true;
    }

    private function createTable()
    {
        if ($this->error)
            return;

        $n     = 0;
        $pKey  = '';
        $query = 'CREATE '.($this->tempTable ? 'TEMPORARY' : '').' TABLE `'.$this->tableName.'` (';

        if ($this->isGameTable)
        {
            $query .= '`idx` BIGINT(20) NOT NULL, ';
            $pKey   = 'idx';
        }

        foreach (str_split($this->format) as $idx => $f)
        {
            switch ($f)
            {
                case 'f':
                    $query .= '`'.$this->fields[$n].'` FLOAT NOT NULL, ';
                    break;
                case 's':
                    $query .= '`'.$this->fields[$n].'` TEXT NOT NULL, ';
                    break;
                case 'i':
                case 'n':
                case 'b':
                case 'u':
                    $query .= '`'.$this->fields[$n].'` BIGINT(20) NOT NULL, ';
                    break;
                default:                                    // 'x', 'X', 'd'
                    continue 2;
            }

            if ($f == 'n')
                $pKey = $this->fields[$n];

            $n++;
        }

        if ($pKey)
            $query .= 'PRIMARY KEY (`'.$pKey.'`) ';
        else
            $query = substr($query, 0, -2);

        $query .=  ') COLLATE=\'utf8_general_ci\' ENGINE=MyISAM';

        DB::Aowow()->query('DROP TABLE IF EXISTS ?#', $this->tableName);
        DB::Aowow()->query($query);
    }

    private function writeToDB()
    {
        if (!$this->dataBuffer || $this->error)
            return;

        // make inserts more manageable
        $fields = $this->fields;

        if ($this->isGameTable)
            array_unshift($fields, 'idx');

        DB::Aowow()->query('INSERT INTO ?# (?#) VALUES (?a)', $this->tableName, $fields, $this->dataBuffer);
        $this->dataBuffer = [];
    }

    private function read()
    {
        // l -   signed long (always 32 bit, machine byte order)
        // V - unsigned long (always 32 bit, little endian byte order)
        $unpackStr = '';
        $unpackFmt = array(
            'x' => 'x/x/x/x',
            'X' => 'x',
            's' => 'V',
            'f' => 'f',
            'i' => 'l',                                     // not sure if 'l' or 'V' should be used here
            'u' => 'V',
            'b' => 'C',
            'd' => 'x4',
            'n' => 'V'
        );

        // Check that record size also matches
        $recSize = 0;
        for ($i = 0; $i < strlen($this->format); $i++)
        {
            $ch = $this->format[$i];
            if ($ch == 'X' || $ch == 'b')
                $recSize += 1;
            else
                $recSize += 4;

            if (!isset($unpackFmt[$ch]))
            {
                CLI::write('unknown format parameter \''.$ch.'\' in format string', CLI::LOG_ERROR);
                return false;
            }

            $unpackStr .= '/'.$unpackFmt[$ch];

            if ($ch != 'X' && $ch != 'x')
                $unpackStr .= 'f'.$i;
        }

        $unpackStr = substr($unpackStr, 1);

        // Optimizing unpack string: 'x/x/x/x/x/x' => 'x6'
        while (preg_match('/(x\/)+x/', $unpackStr, $r))
            $unpackStr = substr_replace($unpackStr, 'x'.((strlen($r[0]) + 1) / 2), strpos($unpackStr, $r[0]), strlen($r[0]));


        // we asserted all DBCs to be identical in structure. pick first header for checks
        $header = reset($this->fileRefs)[2];

        if ($recSize != $header['recordSize'])
        {
            CLI::write('format string size ('.$recSize.') for file '.$this->file.' does not match actual size ('.$header['recordSize'].')', CLI::LOG_ERROR);
            return false;
        }

        // And, finally, extract the records
        $strings  = [];
        $rSize    = $header['recordSize'];
        $rCount   = $header['recordCount'];
        $fCount   = strlen($this->format);
        $strBlock = 4 + 16 + $header['recordSize'] * $header['recordCount'];

        for ($i = 0; $i < $rCount; $i++)
        {
            $row = [];
            $idx = $i;

            // add 'virtual' enumerator for gt*-dbcs
            if ($this->isGameTable)
                $row[-1] = $i;

            foreach ($this->fileRefs as $locId => [$handle, $fullPath, $header])
            {
                $rec = unpack($unpackStr, fread($handle, $header['recordSize']));

                $n = -1;
                for ($j = 0; $j < $fCount; $j++)
                {
                    if (!isset($rec['f'.$j]))
                        continue;

                    if (!empty($row[$j]))
                        continue;

                    $n++;

                    switch ($this->format[$j])
                    {
                        case 's':
                            $curPos = ftell($handle);
                            fseek($handle, $strBlock + $rec['f'.$j]);

                            $str = $chr = '';
                            do
                            {
                                $str .= $chr;
                                $chr = fread($handle, 1);
                            }
                            while ($chr != "\000");

                            fseek($handle, $curPos);
                            $row[$j] = $str;
                            break;
                        case 'f':
                            $row[$j] = round($rec['f'.$j], 8);
                            break;
                        case 'n':                               // DO NOT BREAK!
                            $idx = $rec['f'.$j];
                        default:                                // nothing special .. 'i', 'u' and the likes
                            $row[$j] = $rec['f'.$j];
                    }
                }

                if (!$this->localized)                          // one match is enough
                    break;
            }

            $this->dataBuffer[$idx] = array_values($row);

            if (count($this->dataBuffer) >= $this->bufferSize)
                $this->writeToDB();
        }

        $this->writeToDB();

        $this->endCLean();

        return true;
    }
}

?>
