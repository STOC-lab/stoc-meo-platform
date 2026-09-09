<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Turns a month's gathered figures into the PDF bytes.
 *
 * Dompdf ships only the DejaVu faces, which carry no Japanese glyphs, so the
 * report's own font is declared here from config rather than left to the
 * default. The file is checked before it is declared: a font that is not on
 * the machine would otherwise leave Dompdf resolving nothing and the labels
 * blank, which is worse than falling back to the built-in face.
 *
 * Dompdf will not read a local font outside its chroot, so the font's own
 * directory is added to it.
 */
class ReportPdfRenderer
{
    /**
     * The font formats Dompdf can actually parse. A .ttc collection — which is
     * what the fonts-noto-cjk package installs — is not among them: Dompdf
     * throws part-way through rendering rather than declining it, so the
     * extension is checked here and an unusable font falls back like a missing
     * one.
     */
    public const SUPPORTED_FONT_EXTENSIONS = ['ttf', 'otf'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(array $data): string
    {
        $font = $this->font();

        $pdf = Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => $font['family'],
            'chroot' => array_filter([base_path(), $font['directory']]),
        ])->loadView('reports.monthly', [
            ...$data,
            'font' => $font,
        ]);

        return $pdf->setPaper('a4')->output();
    }

    /**
     * The face the report is set in: the configured font when its file is
     * really there, and the built-in sans otherwise.
     *
     * @return array{family: string, file: string|null, directory: string|null}
     */
    public function font(): array
    {
        $path = $this->config['path'] ?? null;

        if (! is_string($path) || $path === '' || ! is_file($path) || ! $this->isSupported($path)) {
            return ['family' => 'sans-serif', 'file' => null, 'directory' => null];
        }

        return [
            'family' => (string) ($this->config['family'] ?? 'sans-serif'),
            'file' => $path,
            'directory' => dirname($path),
        ];
    }

    protected function isSupported(string $path): bool
    {
        return in_array(
            strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            self::SUPPORTED_FONT_EXTENSIONS,
            true,
        );
    }

    /**
     * Whether the report will come out with Japanese glyphs in it. The command
     * says so plainly rather than leaving a blank PDF to be discovered.
     */
    public function hasEmbeddableFont(): bool
    {
        return $this->font()['file'] !== null;
    }
}
