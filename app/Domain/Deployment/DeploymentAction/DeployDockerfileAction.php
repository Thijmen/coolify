<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentContextCold;
use App\Domain\Deployment\DeploymentOutput;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\Deployment\DeploymentHelper;
use App\Services\Docker\DockerHelper;
use Illuminate\Support\Collection;
use JetBrains\PhpStorm\ArrayShape;

class DeployDockerfileAction extends DeploymentBaseAction
{
    private ApplicationDeploymentQueue $applicationDeploymentQueue;

    private Server $server;

    private Application $application;

    public function __construct(ApplicationDeploymentQueue $applicationDeploymentQueue, Server $server, Application $application, DeploymentHelper $deploymentHelper, DockerHelper $dockerHelper)
    {

        $this->applicationDeploymentQueue = $applicationDeploymentQueue;
        $this->server = $server;
        $this->application = $application;
        parent::__construct($applicationDeploymentQueue, $server, $application, $deploymentHelper, $dockerHelper);
    }

    public function prepare(DeploymentContextCold $config, StandaloneDocker|SwarmDocker $destination, Collection &$savedOutputs): void
    {
        $dockerfileAsBase64 = base64_encode($this->application->dockerfile);
        $this->applicationDeploymentQueue->addDeploymentLog(new DeploymentOutput(output: "Starting deployment of {$this->application->name} to {$this->server->name}."));

        $this->prepareBuilderImage($config, $destination, $savedOutputs);
    }

    public function run(): void
    {
        // TODO: Implement run() method.
    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        // TODO: Implement generateDockerImageNames() method.
    }

    public function buildImage(): void
    {
        // TODO: Implement buildImage() method.
    }
}