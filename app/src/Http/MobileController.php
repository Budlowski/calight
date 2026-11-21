<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\MobileWorkspace;
use App\Application\UserInterface;
use App\Domain\Order\OrderValidationException;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Clock;
use App\View\View;
use DateTimeImmutable;

/**
 * Mobiles Monteurs-Frontend unter /mobile/ (Phase 1): eigenes Layout und eigene Navigation auf
 * Basis derselben Session, Rechte, Module und Fachdienste wie die Büroansicht.
 *
 * Alle Pfade sind relativ zum Host. Unter einer eigenen Subdomain (APP_MOBILE_HOST) gelten dieselben
 * Routen; „/“ führt dort zur mobilen Startseite (siehe UserInterface::landingPath).
 */
final readonly class MobileController
{
    private const LAYOUT = 'layout/mobile';

    public function __construct(
        private View $view,
        private MobileWorkspace $workspace,
        private UserRepository $users,
        private string $publicDirectory,
    ) {
    }

    public function register(Router $router): void
    {
        $router->add('GET', '/mobile', fn (): Response => Response::redirect(UserInterface::MOBILE_HOME));
        $router->add('GET', '/mobile/', fn (): Response => $this->page(fn (array $actor): Response => $this->render('mobile/home', [
            'title' => 'Start',
            'navigation' => 'home',
            'home' => $this->workspace->home($actor, $this->today()),
        ])));
        $router->add('GET', '/mobile/orders', fn (Request $request): Response => $this->page(fn (array $actor): Response => $this->render('mobile/orders', [
            'title' => 'Aufträge',
            'navigation' => 'orders',
            'list' => $this->workspace->orders($request->query, $actor),
        ])));
        $router->add('GET', '/mobile/orders/{id}', fn (Request $request, array $parameters): Response => $this->page(function (array $actor) use ($parameters): Response {
            try {
                $detail = $this->workspace->order((string) $parameters['id'], $actor);
            } catch (OrderValidationException) {
                return $this->render('mobile/error', ['title' => 'Nicht gefunden', 'navigation' => 'orders', 'message' => 'Der Auftrag wurde nicht gefunden.'], 404);
            } catch (AccessDeniedException) {
                return $this->render('mobile/error', ['title' => 'Keine Berechtigung', 'navigation' => 'orders', 'message' => 'Dieser Auftrag ist für Sie nicht freigegeben.'], 403);
            }
            return $this->render('mobile/order', ['title' => $detail['order']['number'], 'navigation' => 'orders', 'detail' => $detail]);
        }));
        // Persönliche Zeiterfassung: Seite als Rahmen, Daten und Änderungen ausschließlich über die
        // bestehende API /api/time-tracking/* (eigener Mitarbeiter, ohne Mitarbeiterauswahl).
        $router->add('GET', '/mobile/times', fn (Request $request): Response => $this->page(fn (array $actor): Response => $this->render('mobile/times', [
            'title' => 'Zeiten',
            'navigation' => 'times',
            'today' => $this->today(),
            'initial' => [
                'date' => $this->date((string) ($request->query['date'] ?? '')),
                'view' => in_array($request->query['view'] ?? '', ['day', 'week', 'month'], true) ? (string) $request->query['view'] : 'day',
                'form' => in_array($request->query['form'] ?? '', ['entry', 'absence', 'travel', 'trip', 'expense'], true) ? (string) $request->query['form'] : '',
                'order' => preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) ($request->query['order'] ?? '')) === 1 ? (string) $request->query['order'] : '',
            ],
        ])));
        $router->add('GET', '/mobile/more', fn (): Response => $this->page(fn (array $actor): Response => $this->render('mobile/more', [
            'title' => 'Mehr',
            'navigation' => 'more',
            'features' => $this->workspace->features($actor),
            'quickActions' => $this->workspace->quickActions($actor),
        ])));

        // PWA-Grundlagen: öffentlich, ohne personenbezogene Daten, Pfade relativ zum Host.
        $router->add('GET', '/mobile/manifest.webmanifest', fn (): Response => $this->manifest());
        $router->add('GET', '/mobile/sw.js', fn (): Response => $this->serviceWorker());

        // Oberflächenwechsel aus dem Benutzermenü (Recht interface.switch, CSRF, ohne JavaScript).
        $router->add('POST', '/interface', fn (Request $request): Response => $this->switchInterface($request));
    }

    /** @param callable(array<string,mixed>):Response $handler */
    private function page(callable $handler): Response
    {
        $user = Session::user();
        return $user === null ? Response::redirect('/login') : $handler($user);
    }

    /** @param array<string,mixed> $data */
    private function render(string $template, array $data, int $status = 200): Response
    {
        $actor = Session::user() ?? [];
        $data['layout'] = self::LAYOUT;
        $data['mobileFeatures'] ??= $this->workspace->features($actor);
        $response = $this->view->render($template, $data, $status);
        // Personenbezogene Seiten nicht im Browser- oder Proxycache ablegen (PWA ohne Offline-Daten).
        return new Response($response->body, $response->status, $response->headers + ['Cache-Control' => 'no-store']);
    }

    private function switchInterface(Request $request): Response
    {
        if (!Session::verifyCsrf(isset($request->post['_csrf']) ? (string) $request->post['_csrf'] : null)) {
            throw new CsrfException('Sicherheitsprüfung fehlgeschlagen.');
        }
        $user = Session::user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $interface = (string) ($request->post['interface'] ?? '');
        if (!in_array($interface, [UserInterface::OFFICE, UserInterface::MOBILE], true)) {
            return new Response('<h1>Ungültige Auswahl</h1><p>Die gewählte Oberfläche ist unbekannt.</p>', 422, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $this->users->updatePreferredInterface((string) $user['id'], $interface, Clock::now());
        $current = $this->users->findActiveById((string) $user['id']);
        if ($current !== null) {
            Session::refreshUser($current);
        }
        return Response::redirect(UserInterface::home($interface));
    }

    private function manifest(): Response
    {
        $manifest = [
            'id' => UserInterface::MOBILE_HOME,
            'name' => 'Calight Monteur',
            'short_name' => 'Calight',
            'description' => 'Mobile Arbeitsoberfläche von Calight für Aufträge, Zeiten und Berichte.',
            'lang' => 'de',
            'dir' => 'ltr',
            'start_url' => UserInterface::MOBILE_HOME,
            'scope' => UserInterface::MOBILE_HOME,
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f4f6f8',
            'theme_color' => '#17324a',
            'icons' => [
                ['src' => '/assets/icons/app/calight-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/icons/app/calight-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/icons/app/calight-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return new Response(
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'no-cache'],
        );
    }

    /**
     * Service Worker aus den lokalen Assets unter /mobile/ ausliefern, damit sein Gültigkeitsbereich
     * genau dem mobilen Frontend entspricht. Er speichert bewusst nichts zwischen (siehe Datei).
     */
    private function serviceWorker(): Response
    {
        $source = file_get_contents($this->publicDirectory . '/assets/mobile-sw.js');
        if ($source === false) {
            return new Response('', 404);
        }
        return new Response($source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => UserInterface::MOBILE_HOME,
        ]);
    }

    private function today(): string
    {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function date(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : $this->today();
    }
}
