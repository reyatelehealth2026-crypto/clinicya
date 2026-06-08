<?php
/**
 * AiStudioImage Test
 *
 * Unit tests for the Nano Banana (Gemini image generation) client + builders.
 * HTTP transport is injected so tests never hit the network.
 *
 * @spec ai-studio-image-upgrade
 */

require_once __DIR__ . '/../../classes/AiStudioImage.php';

use PHPUnit\Framework\TestCase;

class AiStudioImageTest extends TestCase
{
    private function clientReturning(int $status, string $body): AiStudioImage
    {
        return new AiStudioImage(function ($url, $payload) use ($status, $body) {
            return ['status' => $status, 'body' => $body];
        });
    }

    /** A GhostX-style successful generateContent body with one PNG. */
    private function imageBody(string $data = 'QUJD', string $mime = 'image/png'): string
    {
        return json_encode([
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'here is your image'],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $data]],
                ]],
            ]],
        ]);
    }

    // --- model resolution ---------------------------------------------------

    public function testDefaultModelIsNanoBanana2(): void
    {
        $this->assertSame('gemini-3.1-flash-image', AiStudioImage::defaultModel());
    }

    public function testResolveModelAcceptsAllowed(): void
    {
        $this->assertSame('gemini-3-pro-image', AiStudioImage::resolveModel('gemini-3-pro-image'));
        $this->assertSame('gemini-2.5-flash-image', AiStudioImage::resolveModel('gemini-2.5-flash-image'));
    }

    public function testResolveModelFallsBackToDefaultForUnknownOrEmpty(): void
    {
        $this->assertSame('gemini-3.1-flash-image', AiStudioImage::resolveModel('gpt-4o'));
        $this->assertSame('gemini-3.1-flash-image', AiStudioImage::resolveModel(''));
        $this->assertSame('gemini-3.1-flash-image', AiStudioImage::resolveModel(null));
    }

    // --- request builder ----------------------------------------------------

    public function testBuildRequestTextOnly(): void
    {
        $req = AiStudioImage::buildRequest('a cat', [], ['aspectRatio' => '1:1', 'imageSize' => '2K']);

        $parts = $req['contents'][0]['parts'];
        $this->assertSame('a cat', $parts[0]['text']);
        $this->assertCount(1, $parts); // no reference images
        $this->assertSame(['TEXT', 'IMAGE'], $req['generationConfig']['responseModalities']);
        $this->assertSame('1:1', $req['generationConfig']['imageConfig']['aspectRatio']);
        $this->assertSame('2K', $req['generationConfig']['imageConfig']['imageSize']);
    }

    public function testBuildRequestWithReferenceImages(): void
    {
        $refs = [
            ['mime' => 'image/jpeg', 'data' => 'AAAA'],
            ['mime' => 'image/png', 'data' => 'BBBB'],
        ];
        $req = AiStudioImage::buildRequest('put product on a wooden table', $refs, ['aspectRatio' => '4:5', 'imageSize' => '1K']);

        $parts = $req['contents'][0]['parts'];
        $this->assertCount(3, $parts); // text + 2 refs
        $this->assertSame('image/jpeg', $parts[1]['inline_data']['mime_type']);
        $this->assertSame('AAAA', $parts[1]['inline_data']['data']);
        $this->assertSame('BBBB', $parts[2]['inline_data']['data']);
    }

    // --- response parser ----------------------------------------------------

    public function testParseResponseExtractsImageSnakeCase(): void
    {
        $r = AiStudioImage::parseResponse(json_decode($this->imageBody('XYZ', 'image/png'), true));

        $this->assertCount(1, $r['images']);
        $this->assertSame('XYZ', $r['images'][0]['data']);
        $this->assertSame('image/png', $r['images'][0]['mime']);
        $this->assertNull($r['error']);
    }

    public function testParseResponseExtractsImageCamelCase(): void
    {
        $json = ['candidates' => [['content' => ['parts' => [
            ['inlineData' => ['mimeType' => 'image/png', 'data' => 'CAM']],
        ]]]]];
        $r = AiStudioImage::parseResponse($json);

        $this->assertCount(1, $r['images']);
        $this->assertSame('CAM', $r['images'][0]['data']);
    }

    public function testParseResponseSurfacesApiError(): void
    {
        $json = ['error' => ['message' => 'API key not valid']];
        $r = AiStudioImage::parseResponse($json);

        $this->assertSame([], $r['images']);
        $this->assertSame('API key not valid', $r['error']);
    }

    public function testParseResponseReportsNoImageWhenOnlyText(): void
    {
        $json = ['candidates' => [['content' => ['parts' => [['text' => 'cannot do that']]]]]];
        $r = AiStudioImage::parseResponse($json);

        $this->assertSame([], $r['images']);
        $this->assertNotNull($r['error']);
    }

    // --- generate (with injected HTTP) -------------------------------------

    public function testGenerateReturnsImagesOn200(): void
    {
        $svc = $this->clientReturning(200, $this->imageBody('IMG1'));
        $r = $svc->generate('gemini-3.1-flash-image', 'a dog', [], [], 'KEY');

        $this->assertTrue($r['ok']);
        $this->assertSame('IMG1', $r['images'][0]['data']);
    }

    public function testGenerateSurfacesHttpErrorMessage(): void
    {
        $svc = $this->clientReturning(400, json_encode(['error' => ['message' => 'Imagen quota exceeded']]));
        $r = $svc->generate('gemini-3.1-flash-image', 'a dog', [], [], 'KEY');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('quota exceeded', $r['error']);
    }

    public function testEndpointUsesModelAndKey(): void
    {
        $svc = new AiStudioImage();
        $url = $svc->endpoint('gemini-3-pro-image', 'SECRET');
        $this->assertStringContainsString('models/gemini-3-pro-image:generateContent', $url);
        $this->assertStringContainsString('key=SECRET', $url);
    }
}
