<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report Storage
    |--------------------------------------------------------------------------
    |
    | Generated PDFs are written to this disk as {organization}/{YYYY-MM}.pdf.
    | The disk is defined in config/filesystems.php and roots at
    | storage/app/reports, so a report never sits alongside anything the web
    | server serves directly — it is handed out by the download endpoint,
    | which checks the plan and the membership first.
    |
    */

    'disk' => env('REPORTS_DISK', 'reports'),

    /*
    |--------------------------------------------------------------------------
    | Report Font
    |--------------------------------------------------------------------------
    |
    | Dompdf ships only the DejaVu faces, which carry no Japanese glyphs, so a
    | report rendered without a font configured here comes out with its labels
    | blank. Point path at a .ttf or .otf that has them — the fonts-vlgothic
    | package installs one at the path below.
    |
    | It has to be a .ttf or .otf. Dompdf cannot read a .ttc collection, which
    | is what fonts-noto-cjk installs; the renderer declines one rather than
    | letting it throw part-way through a render.
    |
    | The renderer checks the file is really there and falls back to the
    | built-in sans face when it is not, so a missing font costs the report its
    | Japanese rather than failing the run.
    |
    */

    'font' => [
        'family' => env('REPORTS_FONT_FAMILY', 'VL PGothic'),
        'path' => env('REPORTS_FONT_PATH', '/usr/share/fonts/truetype/vlgothic/VL-PGothic-Regular.ttf'),
    ],

];
