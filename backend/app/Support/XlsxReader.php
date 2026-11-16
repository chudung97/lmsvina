<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Đọc file .xlsx không cần thư viện ngoài (file .xlsx là zip các file XML).
 * Trả về giá trị đã hiển thị dạng chuỗi; ô lỗi (#N/A…) trả về null.
 */
class XlsxReader
{
    private ZipArchive $zip;

    /** @var string[] */
    private array $sharedStrings = [];

    /** @var array<string,string> tên sheet => đường dẫn XML */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("Không mở được file Excel: {$path}");
        }
        $this->loadSharedStrings();
        $this->loadSheets();
    }

    /** @return string[] */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * Các dòng của một sheet: [số dòng => [chỉ số cột (A=1) => giá trị]].
     *
     * @return array<int, array<int, ?string>>
     */
    public function rows(string $sheet): array
    {
        if (! isset($this->sheets[$sheet])) {
            throw new RuntimeException("Không tìm thấy sheet \"{$sheet}\". Có: ".implode(', ', $this->sheetNames()));
        }
        $xml = $this->xml($this->sheets[$sheet]);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $type = (string) $cell['t'];
                $value = isset($cell->v) ? (string) $cell->v : (isset($cell->is->t) ? (string) $cell->is->t : '');
                $cells[$this->columnIndex((string) $cell['r'])] = match ($type) {
                    's' => $this->sharedStrings[(int) $value] ?? '',
                    'e' => null,
                    default => $value,
                };
            }
            $rows[(int) $row['r']] = $cells;
        }

        return $rows;
    }

    private function loadSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        foreach ($this->xml('xl/sharedStrings.xml')->si as $item) {
            if (isset($item->t)) {
                $this->sharedStrings[] = (string) $item->t;

                continue;
            }
            $text = '';
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $this->sharedStrings[] = $text;
        }
    }

    private function loadSheets(): void
    {
        $targets = [];
        foreach ($this->xml('xl/_rels/workbook.xml.rels')->Relationship as $relation) {
            $targets[(string) $relation['Id']] = (string) $relation['Target'];
        }
        foreach ($this->xml('xl/workbook.xml')->sheets->sheet as $sheet) {
            $relationId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = ltrim($targets[$relationId] ?? '', '/');
            $this->sheets[trim((string) $sheet['name'])] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }
    }

    private function xml(string $name): SimpleXMLElement
    {
        $content = $this->zip->getFromName($name);
        if ($content === false) {
            throw new RuntimeException("File Excel thiếu phần {$name}.");
        }

        return simplexml_load_string($content);
    }

    private function columnIndex(string $reference): int
    {
        $index = 0;
        foreach (str_split(preg_replace('/\d+/', '', $reference)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }
}
