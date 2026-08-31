<?php

namespace Kompo\Auth\Common\Plugins;

use Condoedge\Utils\Kompo\Plugins\Base\ComponentPlugin;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Sends a logged-out browser to the login screen before the component runs.
 *
 * /_kompo sits under `web` only, so a background call (selfGet, browse, refresh, modal
 * load) never passes the origin route's `auth` middleware: the component boots with
 * auth()->user() null. Opt in per component, like EnableResponsiveTable keys on $isResponsive:
 *
 *     protected $needsAuthentication = true;
 *     // or, decided at runtime:
 *     protected function needsAuthentication(): bool
 *
 * The response is a Kompo redirect for an action and a 302 for a page; an app can
 * replace it with respondUsing(fn (Request $request) => ...).
 */
class RequiresAuthentication extends ComponentPlugin
{
    /** @var \Closure|null */
    protected static $responseResolver;

    public static function respondUsing(?\Closure $resolver): void
    {
        static::$responseResolver = $resolver;
    }

    /** Runs from authorizeBoot(), before created() — the earliest hook on every boot path. */
    public function beforeBoot()
    {
        $this->ensureAuthenticated();
    }

    public function onBoot()
    {
        // The base class throws when a plugin does not define it.
    }

    public function authorize()
    {
        $this->ensureAuthenticated();

        return true;
    }

    public static function unauthenticatedResponse()
    {
        if (static::$responseResolver) {
            return call_user_func(static::$responseResolver, request());
        }

        return kompoAwareRedirect(route('login'));
    }

    protected function ensureAuthenticated(): void
    {
        if (!$this->needsAuthentication() || auth()->check()) {
            return;
        }

        // A console boot (queue, artisan) has no browser to send anywhere.
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return;
        }

        throw new HttpResponseException(static::unauthenticatedResponse());
    }

    protected function needsAuthentication(): bool
    {
        if ($this->componentHasMethod('needsAuthentication')) {
            return (bool) $this->callComponentMethod('needsAuthentication');
        }

        return $this->getComponentProperty('needsAuthentication') === true;
    }
}
