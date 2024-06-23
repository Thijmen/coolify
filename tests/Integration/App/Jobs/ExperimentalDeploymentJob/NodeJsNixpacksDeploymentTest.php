<?php

use App\Jobs\ExperimentalDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\User;
use App\Services\Deployment\DeploymentProvider;
use App\Services\Docker\DockerProvider;
use Illuminate\Support\Facades\Http;

it('should be able to deploy a Node.js Nixpacks project', function () {

    $dockerHostIp = gethostbyname('host.docker.internal');
    // TODO: Ensure that no mails are being send
    // TODO: Assert that these are faked and asserted
    // TODO: See also InstanceSettingsFactory
    $server = Server::factory()->create();

    $publicGitHub = GithubApp::factory()
        ->publicGitHub()
        ->create();

    $server->settings->is_reachable = true;
    $server->settings->is_usable = true;
    $server->settings->save();

    expect($server->isFunctional())
        ->toBeTrue('Server is not functional');

    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'coolify',
    ]);

    $domainNameInDocker = "http://nodejs-testing.{$dockerHostIp}.sslip.io";

    $application = Application::factory()->create([
        'name' => 'NodeJS Fastify Example',
        'fqdn' => "{$domainNameInDocker},http://nodejs-testing.127.0.0.1.sslip.io",
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'base_directory' => '/nodejs',
        'dockerfile' => null,
        'source_type' => GithubApp::class,
        'source_id' => $publicGitHub->id,
        'destination_type' => StandaloneDocker::class,
        'destination_id' => $destination->id,
    ]);

    function assertUrlStatus(string $url, int $statusCode): void
    {
        $response = Http::get($url);

        expect($response->status())
            ->toBe($statusCode);
    }

    assertUrlStatus($domainNameInDocker, 404);

    // Add user to team, otherwise exception in app/Notifications/Channels/EmailChannel.php:18
    $user = User::factory()->create();

    $team = $application->environment->project->team;

    $user->teams()->attach($team, ['role' => 'admin']);

    $applicationDeploymentQueue = ApplicationDeploymentQueue::factory()
        ->create([
            'application_id' => $application->id,
            'application_name' => $application->name,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'destination_id' => $destination->id,
            'force_rebuild' => true,
            'is_webhook' => false,
            'commit' => 'HEAD',
        ]);

    $job = new ExperimentalDeploymentJob($applicationDeploymentQueue->id);

    expect($job)->toBeInstanceOf(ExperimentalDeploymentJob::class);

    $dockerProvider = $this->app->make(DockerProvider::class);

    expect($dockerProvider)
        ->toBeInstanceOf(DockerProvider::class);

    $deploymentProvider = $this->app->make(DeploymentProvider::class);

    expect($deploymentProvider)
        ->toBeInstanceOf(DeploymentProvider::class);

    $job->handle($dockerProvider, $deploymentProvider);

    assertUrlStatus($domainNameInDocker, 200);

    $content = Http::get($domainNameInDocker)->json();
    expect($content)
        ->toHaveKey('hello')
        ->and($content['hello'])
        ->toBe('from nodejs');

    // skip the test per default, but run it if a special env environment is set
})->skip(! getenv('RUN_EXPENSIVE_TESTS'), 'This test is expensive and should only be run in special environments');