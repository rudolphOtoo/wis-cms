<?php

namespace App\Http\Controllers;

use App\Diocese\Diocese;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Return the church logo as an embedded base64 data URI for PDF
     * headers, or null when the logo file is missing. The logo path comes
     * from the active diocese profile (Diocese::string('logo')) so every
     * PDF inherits the install's branding; passing null simply omits the
     * image.
     */
    protected function pdfLogoPath(): ?string
    {
        $logoPath = Diocese::string('logo');

        if (! $logoPath) {
            return null;
        }

        // Already an embedded or absolute asset — pass through as-is.
        if (str_starts_with($logoPath, 'data:') || str_starts_with($logoPath, 'http')) {
            return $logoPath;
        }

        $logoFile = public_path(ltrim($logoPath, '/'));

        if (! is_file($logoFile)) {
            return null;
        }

        $mime = mime_content_type($logoFile) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($logoFile));
    }
}
