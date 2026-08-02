<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Return the church logo as an embedded base64 data URI for PDF
     * headers, or null when the logo file is missing. All PDFs share the
     * same branding; passing null simply omits the image.
     */
    protected function pdfLogoPath(): ?string
    {
        $logoFile = public_path('images/wis-logo.png');

        if (! file_exists($logoFile)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(file_get_contents($logoFile));
    }
}
