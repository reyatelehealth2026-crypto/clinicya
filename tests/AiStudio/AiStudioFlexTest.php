<?php
/**
 * AiStudioFlex Test
 *
 * Unit tests for the LINE Flex builder/parser/normalizer. HTTP transport is
 * injected so tests never hit the network.
 *
 * @spec ai-studio-flex-upgrade
 */

require_once __DIR__ . '/../../classes/AiStudioFlex.php';

use PHPUnit\Framework\TestCase;

class AiStudioFlexTest extends TestCase
{
    private function clientReturning(int $status, string $body): AiStudioFlex
    {
        return new AiStudioFlex(function ($url, $payload) use ($status, $body) {
            return ['status' => $status, 'body' => $body];
        });
    }

    /** A generateContent body whose single text part is $text. */
    private function textBody(string $text): string
    {
        return json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]]],
            ]],
        ]);
    }

    // --- bubble count clamping ---------------------------------------------

    public function testClampBubbleCountFloorsAtOne(): void
    {
        $this->assertSame(1, AiStudioFlex::clampBubbleCount(0));
        $this->assertSame(1, AiStudioFlex::clampBubbleCount(-5));
        $this->assertSame(1, AiStudioFlex::clampBubbleCount('not-a-number'));
    }

    public function testClampBubbleCountCapsAtMax(): void
    {
        $this->assertSame(AiStudioFlex::MAX_BUBBLES, AiStudioFlex::clampBubbleCount(50));
        $this->assertSame(3, AiStudioFlex::clampBubbleCount(3));
    }

    // --- system prompt ------------------------------------------------------

    public function testSystemPromptSingleBubbleMentionsBubbleNotCarousel(): void
    {
        $p = AiStudioFlex::buildSystemPrompt('product', '#FF0000', 1, []);
        $this->assertStringContainsString('bubble', $p);
        $this->assertStringContainsString('#FF0000', $p);
        $this->assertStringContainsString('product', $p);
        $this->assertStringNotContainsString('carousel', $p);
    }

    public function testSystemPromptMultiBubbleRequestsExactCount(): void
    {
        $p = AiStudioFlex::buildSystemPrompt('promo', '#06C755', 4, []);
        $this->assertStringContainsString('carousel', $p);
        $this->assertStringContainsString('4', $p);
    }

    public function testSystemPromptListsHeroUrlsWhenProvided(): void
    {
        $p = AiStudioFlex::buildSystemPrompt('product', '#000', 2, ['https://x/a.png', 'https://x/b.png']);
        $this->assertStringContainsString('https://x/a.png', $p);
        $this->assertStringContainsString('https://x/b.png', $p);
    }

    public function testEditSystemPromptPreservesImageUrlsAndAsksJsonOnly(): void
    {
        $p = AiStudioFlex::buildEditSystemPrompt();
        $this->assertStringContainsString('url', $p);     // instructs to keep image.url
        $this->assertStringContainsString('JSON', $p);    // JSON-only output
        $this->assertStringContainsString('bubble', $p);  // valid LINE structure
        $this->assertStringContainsString('carousel', $p);
    }

    // --- request builder ----------------------------------------------------

    public function testBuildRequestSetsJsonMimeAndSystemInstruction(): void
    {
        $req = AiStudioFlex::buildRequest('promote latte', 'be a flex expert', []);
        $this->assertSame('promote latte', $req['contents'][0]['parts'][0]['text']);
        $this->assertSame('application/json', $req['generationConfig']['responseMimeType']);
        $this->assertSame('be a flex expert', $req['systemInstruction']['parts'][0]['text']);
    }

    public function testBuildRequestAttachesVisionRefs(): void
    {
        $refs = [['mime' => 'image/jpeg', 'data' => 'AAAA'], ['mime' => 'image/png', 'data' => 'BBBB']];
        $req = AiStudioFlex::buildRequest('use these', '', $refs);
        $parts = $req['contents'][0]['parts'];
        $this->assertCount(3, $parts); // text + 2 images
        $this->assertSame('image/jpeg', $parts[1]['inline_data']['mime_type']);
        $this->assertSame('BBBB', $parts[2]['inline_data']['data']);
        $this->assertArrayNotHasKey('systemInstruction', $req); // empty system omitted
    }

    // --- flex json parsing --------------------------------------------------

    public function testParseFlexJsonStripsFences(): void
    {
        $flex = AiStudioFlex::parseFlexJson("```json\n{\"type\":\"bubble\",\"body\":{}}\n```");
        $this->assertSame('bubble', $flex['type']);
    }

    public function testParseFlexJsonUnwrapsFlexMessageEnvelope(): void
    {
        $flex = AiStudioFlex::parseFlexJson('{"type":"flex","altText":"hi","contents":{"type":"bubble"}}');
        $this->assertSame('bubble', $flex['type']);
    }

    public function testParseFlexJsonUnwrapsBubbleKey(): void
    {
        $flex = AiStudioFlex::parseFlexJson('{"bubble":{"type":"bubble","body":{}}}');
        $this->assertSame('bubble', $flex['type']);
    }

    public function testParseFlexJsonReturnsNullOnGarbage(): void
    {
        $this->assertNull(AiStudioFlex::parseFlexJson('not json at all'));
        $this->assertNull(AiStudioFlex::parseFlexJson(''));
    }

    // --- normalize to bubble count -----------------------------------------

    public function testNormalizeSingleBubbleUnwrapsCarousel(): void
    {
        $carousel = ['type' => 'carousel', 'contents' => [
            ['type' => 'bubble', 'id' => 'a'],
            ['type' => 'bubble', 'id' => 'b'],
        ]];
        $out = AiStudioFlex::normalizeToBubbleCount($carousel, 1);
        $this->assertSame('bubble', $out['type']);
        $this->assertSame('a', $out['id']);
    }

    public function testNormalizeWrapsSingleBubbleIntoCarouselAndPads(): void
    {
        $bubble = ['type' => 'bubble', 'id' => 'only'];
        $out = AiStudioFlex::normalizeToBubbleCount($bubble, 3);
        $this->assertSame('carousel', $out['type']);
        $this->assertCount(3, $out['contents']);
        $this->assertSame('only', $out['contents'][2]['id']); // padded by cloning
    }

    public function testNormalizeTrimsExtraBubbles(): void
    {
        $carousel = ['type' => 'carousel', 'contents' => [
            ['type' => 'bubble', 'id' => 'a'],
            ['type' => 'bubble', 'id' => 'b'],
            ['type' => 'bubble', 'id' => 'c'],
        ]];
        $out = AiStudioFlex::normalizeToBubbleCount($carousel, 2);
        $this->assertCount(2, $out['contents']);
        $this->assertSame('b', $out['contents'][1]['id']);
    }

    // --- hero injection -----------------------------------------------------

    public function testInjectHeroNoOpWhenNoUrls(): void
    {
        $bubble = ['type' => 'bubble', 'body' => []];
        $out = AiStudioFlex::injectHeroImages($bubble, []);
        $this->assertArrayNotHasKey('hero', $out);
    }

    public function testInjectHeroOnSingleBubble(): void
    {
        $bubble = ['type' => 'bubble', 'body' => []];
        $out = AiStudioFlex::injectHeroImages($bubble, ['https://x/a.png']);
        $this->assertSame('image', $out['hero']['type']);
        $this->assertSame('https://x/a.png', $out['hero']['url']);
        $this->assertSame('cover', $out['hero']['aspectMode']);
    }

    public function testInjectHeroCyclesUrlsAcrossCarousel(): void
    {
        $carousel = ['type' => 'carousel', 'contents' => [
            ['type' => 'bubble'], ['type' => 'bubble'], ['type' => 'bubble'],
        ]];
        $out = AiStudioFlex::injectHeroImages($carousel, ['https://x/a.png', 'https://x/b.png']);
        $this->assertSame('https://x/a.png', $out['contents'][0]['hero']['url']);
        $this->assertSame('https://x/b.png', $out['contents'][1]['hero']['url']);
        $this->assertSame('https://x/a.png', $out['contents'][2]['hero']['url']); // cycles
    }

    // --- generate (injected HTTP) ------------------------------------------

    public function testGenerateReturnsFlexOn200(): void
    {
        $svc = $this->clientReturning(200, $this->textBody('{"type":"bubble","body":{}}'));
        $r = $svc->generate('hi', 'sys', [], 'KEY');
        $this->assertTrue($r['ok']);
        $this->assertSame('bubble', $r['flex']['type']);
        $this->assertNull($r['error']);
    }

    public function testGenerateSurfacesHttpError(): void
    {
        $svc = $this->clientReturning(400, json_encode(['error' => ['message' => 'quota exceeded']]));
        $r = $svc->generate('hi', 'sys', [], 'KEY');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('quota exceeded', $r['error']);
    }

    public function testGenerateFailsWhenModelReturnsNonJson(): void
    {
        $svc = $this->clientReturning(200, $this->textBody('sorry, I cannot'));
        $r = $svc->generate('hi', 'sys', [], 'KEY');
        $this->assertFalse($r['ok']);
        $this->assertNull($r['flex']);
    }

    public function testGenerateSurfacesBlockReason(): void
    {
        $body = json_encode(['promptFeedback' => ['blockReason' => 'SAFETY']]);
        $svc = $this->clientReturning(200, $body);
        $r = $svc->generate('hi', 'sys', [], 'KEY');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('SAFETY', $r['error']);
    }

    public function testGenerateHandlesTransportException(): void
    {
        $svc = new AiStudioFlex(function ($url, $payload) {
            throw new RuntimeException('network down');
        });
        $r = $svc->generate('hi', 'sys', [], 'KEY');
        $this->assertFalse($r['ok']);
        $this->assertNull($r['flex']);
        $this->assertStringContainsString('network down', $r['error']);
    }

    public function testEndpointUsesFlashModelAndKey(): void
    {
        $svc = new AiStudioFlex();
        $url = $svc->endpoint('SECRET');
        $this->assertStringContainsString('models/gemini-flash-latest:generateContent', $url);
        $this->assertStringContainsString('key=SECRET', $url);
    }
}
