<?php
require __DIR__ . '/vendor/autoload.php';
include './of_database.php';

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('contact');
    $client->setScopes(Google_Service_PeopleService::CONTACTS);
    $client->setAuthConfig('credentials_oauth.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    // The file token.json stores the user's access and refresh tokens, and is
    // created automatically when the authorization flow completes for the first
    // time.
    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}


// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_PeopleService($client);

$optParams = array(
  'personFields' => 'names,emailAddresses,phoneNumbers',
);
$results = $service->people_connections->listPeopleConnections('people/me', $optParams);



// Getting database contacts
$contactDetails = array();
$conn = mysqli_connect(DB_HOST , DB_USER , DB_PASS , DB_TABLE);
$SELECT1 = "SELECT * From contact";
$result = mysqli_query($conn, $SELECT1);
while( $row = mysqli_fetch_array($result)) {
	$contactDetails[$row['mobile']] = $row;	
    $contactDetails[$row['mobile']]["status"] = 0;
}



if (count($results->getConnections()) == 0) {
  print "No connections found.\n";
} else {
  foreach ($results->getConnections() as $person) {
	$present = 0;
	$update = 0;
	foreach ($person->getPhoneNumbers() as $no) {
		$noValue = str_replace(' ', '', $no->value);
		if ($contactDetails[$noValue] != "") {
			$present = 1;
			$contactDetails[$noValue]["status"] = 1;
		}
	}

	if ($present == 1){
		$names = $person->getNames();
		$names = $names[0];
		if ($contactDetails[$noValue]["f_name"] != $names->givenName or $contactDetails[$noValue]["l_name"] != $names->familyName){
			$update = 1;
		}
	}

	if ($update == 1){
		$createService = new Google_Service_PeopleService($client);
		$contact = new Google_Service_PeopleService_Person();
		$name = new Google_Service_PeopleService_Name();

		$name->setGivenName($contactDetails[$noValue]["f_name"]);
		$name->setFamilyName($contactDetails[$noValue]["l_name"]);
		$contact->setNames($name);
		$contact->setEtag($person->etag);

		$phone1 = new Google_Service_PeopleService_PhoneNumber();
		$phone1->setValue($noValue);
		$contact->setPhoneNumbers($phone1);

		$optParams = array(
		  'personFields' => 'names',
		  'updatePersonFields' => 'names',
		);

		$exe = $createService->people->updateContact($person->resourceName, $contact, $optParams)->execute;
		$contactDetails[$noValue]["status"] = 1;
	}
  }
}

foreach($contactDetails as $key=>$value){
	if($value["status"] == 1){
		continue;
	}
	echo "insert";
	$service = new Google_Service_PeopleService($client);
	$person = new Google_Service_PeopleService_Person();

	$name = new Google_Service_PeopleService_Name();
	$name->setGivenName($value["f_name"]);
	$name->setFamilyName($value["l_name"]);
	$person->setNames($name);


	$phone1 = new Google_Service_PeopleService_PhoneNumber();	
	$phone1->setValue($value["mobile"]);
	$person->setPhoneNumbers($phone1);
	
	$exe = $service->people->createContact($person)->execute;
}

