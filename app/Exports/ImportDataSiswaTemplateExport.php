<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ImportDataSiswaTemplateExport implements FromArray, WithHeadings, WithTitle
{
    public function headings(): array
    {
        return [
            'nis',
            'nodaftar',
            'nama',
            'unit',
            'kelas',
            'kelompok',
            'angkatan',
            'gender',
            'alamat',
            'ortu',
            'no_wa',
        ];
    }

    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Data Siswa';
    }
}
