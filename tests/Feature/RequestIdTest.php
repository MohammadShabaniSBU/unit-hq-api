<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\RequestId;
use Tests\Fixtures\CaptureRequestIdJob;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestId::clear();
        CaptureRequestIdJob::$captured = null;
        parent::tearDown();
    }

    public function test_middleware_generates_and_echoes_request_id(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertSame($response->headers->get('X-Request-Id'), RequestId::get());
    }

    public function test_middleware_honors_incoming_request_id(): void
    {
        $id = '11111111-2222-3333-4444-555555555555';

        $response = $this->withHeader('X-Request-Id', $id)->get('/up');

        $response->assertOk();
        $response->assertHeader('X-Request-Id', $id);
        $this->assertSame($id, RequestId::get());
    }

    public function test_job_restores_request_id_from_payload(): void
    {
        $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        RequestId::set($id);

        CaptureRequestIdJob::$captured = null;
        dispatch(new CaptureRequestIdJob);

        $this->assertSame($id, CaptureRequestIdJob::$captured);
    }
}
