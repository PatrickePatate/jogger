<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Metrics\PrometheusExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MetricsController extends Controller
{
    public function __invoke(Request $request, PrometheusExporter $exporter): Response
    {
        $expected = config('jogger.metrics_token');
        // Without a configured token the endpoint does not exist.
        abort_if(! is_string($expected) || $expected === '', 404);

        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer', 'Content-Type' => 'text/plain']);
        }

        return response($exporter->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
