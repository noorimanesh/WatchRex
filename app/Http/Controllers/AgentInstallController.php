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

    public function windowsInstaller()
    {
        return $this->script('install.ps1', 'text/plain');
    }

    public function windowsAgent()
    {
        return $this->script('watchrex-agent.ps1', 'text/plain');
    }

    private function script(string $file, string $type = 'text/x-shellscript')
    {
        $body = str_replace('__WATCHREX_URL__', rtrim(config('app.url'), '/'), file_get_contents(resource_path('agent/'.$file)));

        return response($body, 200, [
            'Content-Type' => $type.'; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
