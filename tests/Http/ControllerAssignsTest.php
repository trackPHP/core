<?php
declare(strict_types=1);

namespace TrackPHP\Tests\Http;

use PHPUnit\Framework\TestCase;
use TrackPHP\Http\Controller;
use TrackPHP\Http\Request;
use TrackPHP\Http\Response;
use TrackPHP\View\FakeViewRenderer;

final class ControllerAssignsTest extends TestCase
{
    private FakeViewRenderer $viewRenderer;

    protected function setUp(): void
    {
        $this->viewRenderer = new FakeViewRenderer();
    }

    private function makeRequest(): Request
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/',
            'HTTPS'          => 'off',
            'HTTP_HOST'      => 'example.test',
        ];
        // Adjust if your factory differs
        return Request::create($server, [], [], [], '');
    }

    public function test_viewData_is_passed_to_view(): void
    {
        $req = $this->makeRequest();
        $controller = new class($req, $this->viewRenderer) extends Controller {};

        // Simulate action code
        $controller->greeting = 'Hi';
        $controller->name     = 'Jeff';

        $response = $controller->render('fake/show');
        $this->assertSame('fake/show', $this->viewRenderer->lastTemplate);
        $this->assertSame(['greeting' => 'Hi', 'name' => 'Jeff'], $this->viewRenderer->lastData);
        $this->assertStringContainsString('<fake>fake/show</fake>', $response->body());
    }

    public function test_it_redirects(): void
    {
        $req = $this->makeRequest();
        $controller = new class($req, $this->viewRenderer) extends Controller {};
        $response = $controller->redirectTo('/home');

        $this->assertSame(302, $response->status());
        $this->assertSame('/home', $response->header('Location'));
    }

    public function test_it_outputs_json(): void
    {
        $req = $this->makeRequest();
        $controller = new class($req, $this->viewRenderer) extends Controller {};
        $response = $controller->json(['ok' => 1, 'name' => 'Jeff']);

        $this->assertSame(200, $response->status());
        $this->assertSame('{"ok":1,"name":"Jeff"}', $response->body());
    }

    public function test_internal_props_are_not_exposed_to_view(): void
    {
        $req = $this->makeRequest();
        $controller = new class($req, $this->viewRenderer) extends Controller {};
        // These are internal and should NOT appear in viewData()
        $controller->_hidden = 'hide me';
        $controller->_stillHidden = 'still hiding';

        // Regular assign should appear
        $controller->title = 'Hello';

        $response = $controller->render('fake/show');
        $this->assertSame('fake/show', $this->viewRenderer->lastTemplate);
        $this->assertSame(['title' => 'Hello'], $this->viewRenderer->lastData);
        $this->assertArrayNotHasKey('_hidden', $this->viewRenderer->lastData);
        $this->assertArrayNotHasKey('_stillHidden', $this->viewRenderer->lastData);
    }

    public function test_render_marks_render_as_performed(): void
    {
        $req = $this->makeRequest();
        $controller = new class($req, $this->viewRenderer) extends Controller {};
        $response = $controller->render('fake/show');
        $this->assertSame(200, $response->status());
        $this->assertTrue($controller->hasPerformed());
        $this->assertSame($response, $controller->performedResponse());
    }

    public function test_magic_get_isset_unset_behaviour(): void
    {
        $req = $this->makeRequest();
        $controller = new class($req, $this->viewRenderer) extends Controller {};

        // Not set yet
        $this->assertNull($controller->flashMessage);
        $this->assertFalse(isset($controller->flashMessage));

        // Set via __set
        $controller->flashMessage = 'Saved!';
        $this->assertSame('Saved!', $controller->flashMessage);
        $this->assertTrue(isset($controller->flashMessage));

        // Unset via __unset
        unset($controller->flashMessage);
        $this->assertNull($controller->flashMessage);
        $this->assertFalse(isset($controller->flashMessage));

        // Underscored keys are ignored by __set/__get/__isset
        $controller->_secret = 'nope';
        $this->assertNull($controller->_secret);
        $this->assertFalse(isset($controller->_secret));
    }
}
