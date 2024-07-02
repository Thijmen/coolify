<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentBaseAction;
use App\Enums\ProcessStatus;
use App\Jobs\ApplicationPullRequestUpdateJob;

class DeployPullRequestAction extends DeploymentBaseAction
{
    public function run(): void
    {
        $server = $this->getContext()->getCurrentServer();
        $application = $this->context->getApplication();
        $applicationDeploymentQueue = $this->context->getApplicationDeploymentQueue();
        $pullRequestId = $applicationDeploymentQueue->pull_request_id;

        $preview = $application->generate_preview_fqdn($pullRequestId);

        if ($application->is_github_based()) {
            // TODO: refactor this job
            ApplicationPullRequestUpdateJob::dispatch(application: $application, preview: $preview, deployment_uuid: $applicationDeploymentQueue->deployment_uuid, status: ProcessStatus::IN_PROGRESS);
        }

        if ($application->build_pack === 'dockerfile') {
            if (data_get($application, 'dockerfile_location')) {
                $this->context->getDeploymentResult()->setDockerfileLocation($application->dockerfile_location);
            }
        }

        if ($application->build_pack === 'dockercompose') {
            $dockerComposeAction = new DeployDockerComposeAction($this->context);
            $dockerComposeAction->run();

            return;
        }

        $this->context->getDeploymentResult()->setNewVersionHealthy(true);
        $this->generateDockerImageNames();
        $this->addSimpleLog("Starting experimental pull request (#{$pullRequestId}) deployment of  {$application->customRepository()['repository']}:{$application->git_branch} to {$server->name}");

        $this->prepareBuilderImage();
        $this->checkGitIfBuildNeeded();
        $this->cloneRepository();
        $this->cleanupGit();

        if ($application->build_pack === 'nixpacks') {
            // FIXME: this is not right, as we are calling a private function. perhaps another solution?
            $this->callNixpacksConfigs();
        }

        $this->generateComposeFile();
        $this->generateBuildEnvVariables();

        if ($application->build_pack === 'dockerfile') {
            // FIXME: this is not right, as we are calling a private function. perhaps another solution?
            $this->callAddBuildEnvVariablesToDockerfile();
        }

        $this->buildImage();

        $this->pushToDockerRegistry();

        // $this->stopRunningContainer();

        $this->rollingUpdate();

    }

    // FIXME: this is not right, as we are calling a private function. perhaps another solution?
    private function callNixpacksConfigs(): void
    {
        $nixpacksAction = new DeployNixpacksAction($this->context);

        $caller = function () {
            return $this->generateNixpacksConfigs();
        };

        $caller->call($nixpacksAction);
    }

    // FIXME: this is not right, as we are calling a private function. perhaps another solution?
    private function callAddBuildEnvVariablesToDockerfile(): void
    {
        $dockerfileAction = new DeployDockerfileAction($this->context);

        $caller = function () {
            return $this->addBuildEnvVariablesToDockerfile();
        };

        $caller->call($dockerfileAction);
    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        $application = $this->getApplication();
        $deployment = $this->getContext()->getApplicationDeploymentQueue();

        $baseName = $application->docker_registry_image_name
            ? $application->docker_registry_image_name.':pr-'.$deployment->pull_request_id
            : $application->uuid.':pr-'.$deployment->pull_request_id;

        return [
            'buildImageName' => $baseName.'-build',
            'productionImageName' => $baseName,
        ];
    }

    public function buildImage(): void
    {
        $this->addSimpleLog('-------------------------------');
        $this->addSimpleLog('Experimental Deployment: running DeployPullRequestAction::buildImage()');

        $this->addSimpleLog('Building docker image started.');
        $this->addSimpleLog('To check the current progress, click on Show Debug Logs.');

        $application = $this->context->getApplication();

        if ($application->build_pack === 'dockerfile') {
            $dockerfileAction = new DeployDockerfileAction($this->context);
            $dockerfileAction->buildImage();
        } elseif ($application->build_pack === 'nixpacks') {
            $nixpacksAction = new DeployNixpacksAction($this->context);
            $nixpacksAction->buildImage();
        } elseif ($application->build_pack === 'dockercompose') {
            $dockercomposeAction = new DeployDockerComposeAction($this->context);
            $dockercomposeAction->buildImage();

        } else {

            // TODO: STATIC

            $this->addSimpleLog('Build pack not supported.');
        }
    }
}
