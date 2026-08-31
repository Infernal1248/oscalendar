<?php

namespace App\Http\Controllers;

use App\Models\FlightSegment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FlightDocumentController extends Controller
{
    public function ofp(FlightSegment $flightSegment): StreamedResponse
    {
        abort_unless($flightSegment->ofp_pdf_path
            && Storage::disk('local')->exists($flightSegment->ofp_pdf_path), 404);

        $flight = preg_replace('/[^\pL\pN_-]+/u', '-', (string) $flightSegment->flight_number) ?: 'flight';

        return Storage::disk('local')->download($flightSegment->ofp_pdf_path, 'OFP-'.$flight.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
