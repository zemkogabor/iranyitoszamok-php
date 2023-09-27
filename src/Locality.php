<?php

declare(strict_types = 1);

namespace Irsz;

use LogicException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\Exception\ReaderNotOpenedException;
use OpenSpout\Reader\XLSX\Reader;

class Locality
{
    public const SOURCE_KSH_HNT = 'https://www.ksh.hu/docs/helysegnevtar/hnt_letoltes_2022.xlsx';
    public const SOURCE_MAGYAR_POSTA_IRSZ = 'https://www.posta.hu/static/internet/download/Iranyitoszam-Internet_uj.xlsx';

    /**
     * Az összes települést tartalmazza, Magyarország Helységnévtára (https://www.ksh.hu/apps/hntr.main) és a
     * Magyer Posta Zrt. adatbázis alapján.
     *
     * A település nevével van indexelve a tömb, amiből az következik, hogy a településnevek egyediek.
     *
     * @var array<string, LocalitySettlement>
     */
    public static array $settlementsByName = [];

    public function __construct()
    {
    }

    /**
     * Kitölti a település alap adatokat a KSH-s adatbázis alapján: név, régió
     *
     * @return void
     * @throws IOException
     * @throws ReaderNotOpenedException
     */
    protected static function collectSettlementBaseInfo(): void
    {
        $file = sys_get_temp_dir() . '/ksh.xlsx';

        file_put_contents($file, file_get_contents(self::SOURCE_KSH_HNT));

        $reader = new Reader();
        $reader->open($file);

        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== 'Helységek 2022.01.01.') {
                continue;
            }

            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                if ($rowIndex <= 3) {
                    // Fejléc kihagyása
                    continue;
                }

                $cells = $row->getCells();

                if ($cells[0]->getValue() === 'Összesen') {
                    // Utolsó "összesítő" sor kihagyása
                    continue;
                }

                $settlementName = trim($cells[0]->getValue());

                // Budapest és kerületei hivatalosan nem tartoznak egy megyéhez sem, ezért null. (Budapest, Budapest 01. ker., Budapest 02. ker., stb.)
                if (str_starts_with($settlementName, 'Budapest')) {
                    $regionName = null;
                } else {
                    $regionName = trim($cells[3]->getValue());

                    if ($regionName === '') {
                        throw new LogicException('Region name missing, settlement: ' . $settlementName);
                    }
                }

                if (array_key_exists($settlementName, self::$settlementsByName)) {
                    throw new LogicException('Duplicated settlement name: ' . $settlementName);
                }

                self::$settlementsByName[$settlementName] = new LocalitySettlement($settlementName, $regionName);
            }
        }

        unlink($file);
    }

    /**
     * Kitölti a településekhez tartozó irányítószámokat a Magyar Posta adatbázisa alapján.
     *
     * @return void
     * @throws IOException
     * @throws ReaderNotOpenedException
     */
    public static function collectPostalCodes(): void
    {
        $file = sys_get_temp_dir() . '/posta-irsz.xlsx';

        file_put_contents($file, file_get_contents(self::SOURCE_MAGYAR_POSTA_IRSZ));

        $reader = new Reader();
        $reader->open($file);

        foreach ($reader->getSheetIterator() as $sheet) {
            switch ($sheet->getName()) {
                case 'Települések':
                    foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                        if ($rowIndex <= 2) {
                            // Fejléc kihagyása
                            continue;
                        }

                        $cells = $row->getCells();
                        $postalCode = (string) $cells[0]->getValue();
                        $settlementName = trim($cells[1]->getValue());

                        static::validateSettlementNameExists($settlementName);

                        self::$settlementsByName[$settlementName]->addPostalCode($postalCode);
                    }
                    break;
                case 'Bp.u.':
                    foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                        if ($rowIndex <= 1) {
                            // Fejléc kihagyása
                            continue;
                        }

                        $cells = $row->getCells();

                        $postalCode = (string) $cells[0]->getValue();
                        $districtInRomanNumeral = $cells[8]->getValue();
                        $streetName = $cells[1]->getValue();

                        // Margitsziget nem tartozik kerülethez, hanem "a főváros közvetlen igazgatása alá tartozik",
                        // ezért a "Budapest" nevű településhez kötjük.
                        //https://hu.wikipedia.org/wiki/Margit-sziget
                        if ($districtInRomanNumeral === 'Margitsziget' || $streetName === 'Margitsziget') {
                            $settlementName = 'Budapest';
                        } else {
                            $districtNumber = static::romanToInt(rtrim($districtInRomanNumeral, '.'));

                            // Budapest 01. ker. és Budapest 12. ker., tehát ki kell írni a 0-át.
                            $districtNumberFormatted = str_pad((string) $districtNumber, 2, '0', STR_PAD_LEFT);

                            $settlementName = 'Budapest ' . $districtNumberFormatted  . '. ker.';
                        }

                        static::validateSettlementNameExists($settlementName);

                        self::$settlementsByName[$settlementName]->addPostalCode($postalCode);
                    }
                    break;
                case 'Miskolc u.':
                case 'Debrecen u.':
                case 'Szeged u.':
                case 'Pécs u.':
                case 'Győr u.':
                    foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                        if ($rowIndex <= 1) {
                            // Fejléc kihagyása
                            continue;
                        }

                        $cells = $row->getCells();

                        $postalCode = (string) $cells[0]->getValue();

                        // A többi olyan település, ahol utca szintre van bontva az irányítószám, ott
                        // A sheet névből vesszük, hogy mihez adjuk hozzá az irányítószámokat.
                        // Pl.: "Szeged u." -> "Szeged"
                        $settlementName = rtrim($sheet->getName(), ' u.');

                        static::validateSettlementNameExists($settlementName);

                        self::$settlementsByName[$settlementName]->addPostalCode($postalCode);
                    }
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Visszadja a településeket, amiket a letöltött fájlokból nyert ki.
     *
     * @return LocalitySettlement[]
     * @throws IOException
     * @throws ReaderNotOpenedException
     */
    public static function settlements(): array
    {
        // Fontos a sorrend.
        static::collectSettlementBaseInfo();
        static::collectPostalCodes();

        return array_values(self::$settlementsByName);
    }

    /**
     * Római számot átalakítja integerré (lehetne rá lib, de annyira egyszerű, hogy végül inkább megírtam)
     *
     * @param string $romanNumeral
     * @return int
     */
    protected static function romanToInt(string $romanNumeral): int
    {
        $romans = [
            'M' => 1000,
            'CM' => 900,
            'D' => 500,
            'CD' => 400,
            'C' => 100,
            'XC' => 90,
            'L' => 50,
            'XL' => 40,
            'X' => 10,
            'IX' => 9,
            'V' => 5,
            'IV' => 4,
            'I' => 1,
        ];

        $result = 0;
        foreach ($romans as $key => $value) {
            while (str_starts_with($romanNumeral, $key)) {
                $result += $value;
                $romanNumeral = substr($romanNumeral, strlen($key));
            }
        }
        return $result;
    }

    /**
     * Validálja, hogy létezik-e a településnév
     *
     * @param string $settlementName
     * @return void
     */
    protected static function validateSettlementNameExists(string $settlementName): void
    {
        if (!array_key_exists($settlementName, self::$settlementsByName)) {
            throw new LogicException('Settlement not found: ' . $settlementName);
        }
    }
}
