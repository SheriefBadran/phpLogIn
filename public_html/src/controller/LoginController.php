<?php
	require_once(HelperPath.DS.'SessionModel.php');
	require_once(ModelPath.DS.'Validator.php');
	require_once(ModelPath.DS.'UserModel.php');
	require_once(HelperPath.DS.'HTMLView.php');
	require_once(ViewPath.DS.'LoginView.php');
	require_once(ViewPath.DS.'MemberView.php');

	class LoginController {
		// REMINDER: WHEN CREATING MODELS, THE MODEL MIGHT HAVE TO INHERIT FROM THE DATABASE OBJECTS IN THE HELPERS FOLDER?

		private $sessionModel;
		private $loginView;
		private $memberView;
		private $userModel;
		private static $hashString = "sha256";

		function __construct () {

			$this->sessionModel = new SessionModel();
			$this->loginView = new LoginView();
			$this->memberView = new MemberView();
			$this->userModel = new UserModel();
		}

		public function RunLoginLogic () {

			global $remote_ip;
			global $b_ip;
			global $user_agent;

			// Set page reload flag
			$onReload = false;

			// Assign needed instances in local variables (Experiment).
			$loginView = clone $this->loginView;		
			$memberView = clone $this->memberView;
			$sessionModel = clone $this->sessionModel;

			// RENDER START PAGE, Render loginView if user is not already logged in and did not press Login Button
			if(!$sessionModel->IsLoggedIn() && !$loginView->UserPressLoginButton() && !$memberView->RememberMe()) {

				// Generate output data
				$loginView->RenderLoginForm();
				return;
			}

			// USER LOGS OUT
			if ($memberView->UserPressLogoutButton()) {
				
				$this->LogoutUser();
				return true;
			}

			// USER MAKES A LOGIN REQUEST
			if ($loginView->UserPressLoginButton()) {
				
				$result = $this->AuthenticateUser();

				// If comparison to database succeeded login user and render memberarea.
				if ($result === true) {

					$autoLoginIsSet = $loginView->AutoLoginIsChecked();
					$memberView->RenderMemberArea($autoLoginIsSet, $onReload);		
					return true;
				}
				else {

					// render loginform with errormessage.
					$loginView->RenderLoginForm($result);
				}
			}

			// USER IS ALREADY LOGGED IN AND RELOADS PAGE or USER LOGGED IN WITH REMEMBER ME AND RELOADS
			if ($sessionModel->IsLoggedIn() || $memberView->RememberMe()) {

				$onReload = true;

				$validId = hash(self::$hashString, $remote_ip . $user_agent);
				if ($sessionModel->IsStolen($validId)) {
					
					$this->memberView->LogoutUser();
					$this->loginView->RenderLoginForm();
					return false;
				}

				// Check if somebody manipulated cookies.
				// This if statement only checks the or block if user klicked remember me because of the && - operator.
				if ( $memberView->RememberMe() && ($this->UserCredentialManipulated() || $this->CookieDateManipulated()) ) {

					$this->LogoutUser(false);
					return false;
				}

				$memberView->RenderMemberArea(false, $onReload);

				return true;
			}
		}

		// HELPER FUNCTIONS FOR THIS CONTROLLER

		// Authentication logic. 
		protected function AuthenticateUser () {

			$username = $this->loginView->GetUsername();
			$password = $this->loginView->GetPassword();

			// 1. CLIENT-WORK: VALIDATE IN-DATA 
			$message = $this->loginView->Validate();


			if ($message !== true) {
				
				return $message;
			}

			// 2. SERVER-AUTHENTICATION: CHECK WITH DATABASE IF USERNAME AND PASSWORD EXIST
			$userAuthenticated = $this->userModel->AuthenticateUser($username, $password);
			
			if ($userAuthenticated) {

				// TODO: Check that this is not done more than once.
				$this->sessionModel->LoginUser($this->userModel);

				if ($this->loginView->AutoLoginIsChecked()) {

					// TODO: Change 30 to a constant/variable.
					$cookieTimestamp = time() + 30;
					$this->memberView->SaveUserCredentials($username, $password, $cookieTimestamp);
					$this->userModel->SaveCookieTimestamp($cookieTimestamp, $this->sessionModel->GetUserId());
				}

				return $userAuthenticated;
			}
			else {

				return $this->loginView->GetLoginErrorMessage();
			}
		}

		protected function UserCredentialManipulated () {

			// COMPARE TO HASHED PASSWORD!
			// TODO: MOVE THIS TO THE VIEW.
			// TODO: Get the cookie values from the view before sending them to AuthenticateUser.

			// execute return below if passwords in database are hased.
			// return !@$this->userModel->AuthenticateUser($_COOKIE['username'], $_COOKIE['password']);

			// execute below if passwords in database are not hashed.
			try {

				$username = $this->memberView->GetCookieUsername();
				$password = $this->memberView->GetCookiePassword();				
			}
			catch (\Exception $e) {

				// Handle error: Something went wrong, could not find cookies, return true so that user is thrown out.
				return true;
			}

			return !@$this->userModel->UserCredentialManipulated($username, $password);
		}

		protected function CookieDateManipulated () {

			// TODO: Move this logic to view.
			$currentTime = time();
			$cookieExpTime = (int)($this->userModel->GetCookieDateById());

			return ($currentTime > $cookieExpTime) ? true : false;
		}

		protected function LogoutUser ($isDefaultLogout = true) {

			$this->memberView->LogoutUser();
			$this->loginView->RenderLogoutView($isDefaultLogout);
		}
	}