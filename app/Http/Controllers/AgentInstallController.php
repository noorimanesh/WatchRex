<?php

namespace App\Http\Controllers;

/** Serves the dependency-free bash agent and its installer with this instance's URL baked in. */
class AgentInstallController extends Controller
{
    public function installer()
    {
        return $this->script('install.sh');
    }

    public function agent()
    {
        return $this->script('watchrex-agent.sh');
    }

    private function script(string $file)
    {
        $body = str_replace('__WATCHREX_URL__', rtrim(config('app.url'), '/'), file_get_contents(resource_path('agent/'.$file)));

        return response($body, 200, [
            'Content-Type' => 'text/x-shellscript; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
