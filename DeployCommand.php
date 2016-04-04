<?php
namespace Command;

use AppBundle\Service\AwsFileStorageService;
use Aws\Credentials\CredentialProvider;
use Aws\Sdk;
use Skrz\Bundle\AutowiringBundle\Annotation\Value;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command for deploying app to AWS
 */
class DeployCommand extends Command
{

	/**
	 * @var string
	 * @Value("%kernel.root_dir%/..")
	 */
	public $rootDir;

	/**
	 * @var array
	 * @Value("%aws%")
	 */
	public $awsConfig;

	/**
	 * @var string
	 * @Value("%aws_credentials_profile%")
	 */
	public $awsCredentialsProfile;

	/**
	 * @var string
	 * @Value("%aws_credentials_path%")
	 */
	public $awsCredentialsPath;

	/**
	 * @var string
	 * @Value("%aws_application_name%")
	 */
	public $awsApplicationName;

	/**
	 * @var string
	 * @Value("%aws_code_deploy_bucket%")
	 */
	public $awsCodeDeployBucket;

	/** {@inheritdoc} */
	protected function configure()
	{
		$this
			->setName("deploy")
			->setDescription("Deploy application to AWS")
			->addOption("group", "g", InputOption::VALUE_REQUIRED, "The deployment group's name", null)
			->addOption("description", "d", InputOption::VALUE_REQUIRED, "A comment about the deployment", null);
	}

	/** {@inheritdoc} */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$deploymentGroup = $input->getOption("group");
		$deploymentDescription = $input->getOption("description") ?: "Deploy from CLI on " . date("Y-m-d H:i:s");

		$io = new SymfonyStyle($input, $output);

		if ($io->askQuestion(new Question("Proceed with deployment to group \"{$deploymentGroup}\" with description \"{$deploymentDescription}\"? [y/n]", "y")) !== "y") {
			$io->success("Deployment stopped");
			return;
		}

		$io->comment("Copying app files to /dist folder");

		exec("{$this->rootDir}/scripts/prepare_dist");

		$io->success("Files copied: {$this->rootDir}/dist");

		$io->comment("Create zip archive");

		$zipFilename = "app.zip";
		$zipFilepath = "{$this->rootDir}/dist/{$zipFilename}";
		$this->createZipArchive("{$this->rootDir}/dist", $zipFilepath);

		$io->success("Archive created: {$zipFilepath}");

		$io->comment("Push revision to S3");

		$s3Client = $this->getClient()->createS3();

		$result = $s3Client->putObject([
			'Bucket' => $this->awsCodeDeployBucket,
			'Key' => $zipFilename,
			'Body' => file_get_contents($zipFilepath),
			'ACL' => 'public-read'
		]);

		$s3Client->waitUntil('ObjectExists', [
			'Bucket' => $this->awsCodeDeployBucket,
			'Key' => $zipFilename,
		]);

		$io->success("Object created: {$result["ObjectURL"]}");

		$io->comment("Create deployment");

		$codeDeployClient = $this->getClient()->createCodeDeploy();

		$deployment = $codeDeployClient->createDeployment([
			'applicationName' => $this->awsApplicationName,
			'deploymentGroupName' => $deploymentGroup,
			'revision' => [
				'revisionType' => 'S3',
				's3Location' => [
					'bucket' => $this->awsCodeDeployBucket,
					'key' => $zipFilename,
					'bundleType' => 'zip',
					'eTag' => $result['ETag'],
				],
			],
			'deploymentConfigName' => 'CodeDeployDefault.OneAtATime',
			'description' => $deploymentDescription,
			'ignoreApplicationStopFailures' => true,
		]);
		$deploymentId = $deployment->get("deploymentId");

		$io->success("Deployment created with ID: {$deploymentId}");

		$lastStatus = null;
		$success = false;
		for (; ;) {
			$info = $codeDeployClient->getDeployment([
				"deploymentId" => $deploymentId,
			]);

			if ($info["deploymentInfo"]["status"] === $lastStatus) {
				sleep(1);
				continue;
			}

			if (isset($info["deploymentInfo"]["errorInformation"]) && $info["deploymentInfo"]["errorInformation"]) {
				$io->error("Error occured: {$info["deploymentInfo"]["errorInformation"]["code"]} {$info["deploymentInfo"]["errorInformation"]["message"]}");
				break;
			}

			$io->success("Deployment status: {$info["deploymentInfo"]["status"]}");
			$lastStatus = $info["deploymentInfo"]["status"];

			if (isset($info["deploymentInfo"]["completeTime"]) && $info["deploymentInfo"]["completeTime"]) {
				$success = true;
				break;
			}
			sleep(1);
		}

		if ($success) {
			$io->success("Deployment finished!");
		}
	}

	/**
	 * @return Sdk
	 */
	protected function getClient()
	{
		if (!empty($this->awsCredentialsPath)) {
			$this->awsConfig["credentials"] = CredentialProvider::ini($this->awsCredentialsProfile, $this->awsCredentialsPath);
		}

		return new Sdk($this->awsConfig);
	}

	/**
	 * @param array $awsConfig
	 * @return self
	 */
	public function setAwsConfig($awsConfig)
	{
		$this->awsConfig = $awsConfig;
		return $this;
	}

	/**
	 * @param string $awsCredentialsProfile
	 * @return self
	 */
	public function setAwsCredentialsProfile($awsCredentialsProfile)
	{
		$this->awsCredentialsProfile = $awsCredentialsProfile;
		return $this;
	}

	/**
	 * @param string $awsCredentialsPath
	 * @return self
	 */
	public function setAwsCredentialsPath($awsCredentialsPath)
	{
		$this->awsCredentialsPath = $awsCredentialsPath;
		return $this;
	}

	/**
	 * Archive given source directory to destination filename
	 *
	 * @param string $source
	 * @param string $destination
	 * @see http://stackoverflow.com/a/4914807
	 */
	private function createZipArchive($source, $destination)
	{
		$rootPath = realpath($source);

		$zip = new \ZipArchive();
		$zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

		/** @var \SplFileInfo[] $files */
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($rootPath),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($files as $name => $file) {
			// Skip directories (they would be added automatically)
			if (!$file->isDir()) {
				// Get real and relative path for current file
				$filePath = $file->getRealPath();
				$relativePath = substr($filePath, strlen($rootPath) + 1);

				// Add current file to archive
				$zip->addFile($filePath, $relativePath);
			}
		}

		$zip->close();
	}
}
