<?php

namespace App\Domain\Deployment\DeploymentAction;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentBaseAction;
use App\Domain\Remote\Commands\RemoteCommand;

class DeployStaticAction extends DeploymentBaseAction
{
    public function run(): void
    {
        $application = $this->getApplication();
        $server = $this->getContext()->getCurrentServer();

        $this->addSimpleLog("Starting experimental deployment of {$application->customRepository()['repository']}:{$application->git_branch} to {$server->name}");

        $this->prepareBuilderImage();
        $this->checkGitIfBuildNeeded();
        $this->generateDockerImageNames();

        if (! $this->context->getDeploymentConfig()->isForceRebuild()) {
            $this->checkImageLocallyOrRemote();

            if ($this->shouldSkipBuild()) {
                return;
            }
        }

        $this->cloneRepository();
        $this->cleanupGit();
        $this->generateComposeFile();
        $this->buildImage();
        $this->pushToDockerRegistry();
        $this->rollingUpdate();

    }

    #[ArrayShape(['buildImageName' => 'string', 'productionImageName' => 'string'])]
    public function generateDockerImageNames(): array
    {
        $application = $this->getApplication();

        $commit = $this->getContext()->getApplicationDeploymentQueue()->commit;

        $dockerRegistryImageName = $application->docker_registry_image_name;

        $buildImageName = $dockerRegistryImageName ?: $application->uuid;

        $dockerImageTag = str($commit)->substr(0, 128);

        return [
            'buildImageName' => "{$buildImageName}:{$dockerImageTag}-build",
            'productionImageName' => "{$buildImageName}:{$dockerImageTag}",
        ];
    }

    public function buildImage(): void
    {
        $this->addSimpleLog('----------------------------');
        $this->addSimpleLog('Running DeployStaticAction::buildImage()');

        $application = $this->getApplication();
        $static_image = $application->static_image;

        $deployment = $this->getContext()->getApplicationDeploymentQueue();

        if ($static_image) {
            $this->addSimpleLog('Using static image: '.$static_image);
            $this->addSimpleLog('----------------------------');
            $this->pullLatestImage($static_image);
            $this->addSimpleLog('Continuing with the building process.');
        } else {
            $this->addSimpleLog('No static image found, building from scratch');
        }

        $dockerfile = base64_encode("FROM {$static_image}
        WORKDIR /usr/share/nginx/html/
        LABEL coolify.deploymentId={$deployment->deployment_uuid}
        COPY . .
        RUN rm -f /usr/share/nginx/html/nginx.conf
        RUN rm -f /usr/share/nginx/html/Dockerfile
        COPY ./nginx.conf /etc/nginx/conf.d/default.conf");

        $nginx_config = base64_encode('server {
            listen       80;
            listen  [::]:80;
            server_name  localhost;

            location / {
                root   /usr/share/nginx/html;
                index  index.html;
                try_files $uri $uri.html $uri/index.html $uri/ /index.html =404;
            }

            error_page   500 502 503 504  /50x.html;
            location = /50x.html {
                root   /usr/share/nginx/html;
            }
        }');

        $imageNames = $this->generateDockerImageNames();
        $workDir = $this->getContext()->getDeploymentConfig()->getWorkDir();
        $addHosts = $this->getContext()->getDeploymentConfig()->getAddHosts();
        $buildArgs = $this->generateBuildEnvVariables();

        $buildArgsAsString = $buildArgs->map(function ($value, $key) {
            return "--build-arg {$key}={$value}";
        })->implode(' ');

        $createDockerfile = executeInDocker($deployment->deployment_uuid, "echo '{$dockerfile}' | base64 -d | tee {$workDir}/Dockerfile > /dev/null");
        $createNginxConfig = executeInDocker($deployment->deployment_uuid, "echo '{$nginx_config}' | base64 -d | tee {$workDir}/nginx.conf > /dev/null");

        $buildCommand = "docker build {$addHosts} --network host -f {$workDir}/Dockerfile {$buildArgsAsString} --progress plain -t {$imageNames['productionImageName']} {$workDir}";

        $base64BuildCommand = base64_encode($buildCommand);

        $setBuildCommand = executeInDocker($deployment->deployment_uuid, "echo '{$base64BuildCommand}' | base64 -d | tee /artifacts/build.sh > /dev/null");
        $executeBuildCommand = executeInDocker($deployment->deployment_uuid, 'bash /artifacts/build.sh');

        $this->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand($createDockerfile),
                new RemoteCommand($createNginxConfig),
                new RemoteCommand($setBuildCommand, hidden: true),
                new RemoteCommand($executeBuildCommand, hidden: true),
            ], $deployment, $this->getContext()->getDeploymentResult()->savedLogs);

        $this->addSimpleLog('Building docker image completed');
        $this->addSimpleLog('----------------------------');
    }

    private function pullLatestImage($image): void
    {
        $this->addSimpleLog("Pulling latest image ($image) from the registry.");

        $deployment = $this->getContext()->getApplicationDeploymentQueue();

        $this->context->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand(executeInDocker($deployment->deployment_uuid, "docker pull {$image}"), hidden: true),
            ], $this->context->getApplicationDeploymentQueue(), $this->context->getDeploymentResult()->savedLogs);
    }
}
