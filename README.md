# Symfony console command for deployment to AWS CodeDeploy
If you want to deploy your Symfony application to AWS and use the CodeDeploy service, you're in the right place :)

# How it works
0. remove old **/dist** folder if already exists
1. copy preselected application files to **/dist** folder
2. compress the whole **/dist** folder to zip archive
3. push newly created zip archive to AWS S3 bucket
4. create new deployment in AWS CodeDeploy
5. wait for deployment to finish and show info about progress

# How it will make your life easier
- you don't have to copy the whole application dir to S3 (default behaviour for deployment over AWS CLI)
- you don't have to remember any CLI commands (just **php app/console deploy**)
- deployment process is versioned and transparent

# Prerequisities
- AWS CodeDeploy configuration
- working connection to AWS from your local machine

# Requirements
- symfony
- aws/aws-sdk-php
- skrz/autowiring-bundle (optional)

# Setup
- copy and update *configs/appspec.yml* to your application needs
- copy and update parameters from *configs/parameters.yml* to your app
- copy *DeployCommand.php* to your namespace folder
- configure dependencies for command in your *services.yml* (if you're not using skrz/autowiring-bundle)
- update filepaths in the command if needed
