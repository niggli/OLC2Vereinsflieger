<?php 

	// OLC to Vereinsflieger
	//
	// This script receives start and landing time information from a OLCnotifier (https://github.com/niggli/OLCnotifier)
	// and searches for matching flights in vereinsflieger.de. If it finds a matching flight, it corrects it's timestamps.
	//
	// Versions
	// 1.0 - 31.05.2017 First draft
	

	// Enable error output
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
	
	// global variables, to be replaced in future by config file
	$vereinsfliegerLogin = "";
	$vereinsfliegerPassword = "";
	$pushoverApplicationKey = "";
	$pushoverUserKey = "";

  require_once('VereinsfliegerRestInterface.php');	
  date_default_timezone_set ( "UTC");

	// echo header
	echo "<html><head></head><body><h1>OLCtoVereinsflieger</h1>";

	// check passed variables
	if (isset ($_GET['starttime']))
	{
		$starttimeFromOLC = new DateTime($_GET['starttime']);
		$landingtimeFromOLC = new DateTime($_GET['landingtime']);
		$pilotnameFromOLC = $_GET['pilotname'];
		
		echo "Start time: " . $starttimeFromOLC->format('Y-m-d H:i:s') .  "<br />";
		echo "Landing time: " . $landingtimeFromOLC->format('Y-m-d H:i:s') . "<br />";
		echo "Pilot name: " . $pilotnameFromOLC . "<br />";
		
		$flightidVereinsflieger = findFlightID($starttimeFromOLC, $landingtimeFromOLC, $pilotnameFromOLC);
		
		if ($flightidVereinsflieger > 0)
		{
			correctFlight($flightidVereinsflieger, $starttimeFromOLC, $landingtimeFromOLC);
			sendNotification("Flight from " . $pilotnameFromOLC . " corrected." , $pushoverUserKey);	
		} else
		{
			// no matching flight found, create new
			// #TODO not yet implemented
			//addFlight();
			sendNotification("No matching flight found. Error " . $flightidVereinsflieger, $pushoverUserKey);
		}

	} else
	{
		// nothing set, startpage
		echo "Called without parameters, no functionality.";
	}

	function findFlightID ($starttimeOLC, $landingtimeOLC, $pilotOLC)
	{
		echo "findFlightID()<br />";
		
		global $vereinsfliegerLogin;
		global $vereinsfliegerPassword;
		
		// login to Vereinsflieger
		$a = new VereinsfliegerRestInterface();
		$result = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, 0);
		
		if ($result)
		{
			//load all flights from today
			// ##TODO other dates
			
			echo "success login<br />";
			
			// TEST for specific date
      //$return = $a->GetFlights_date ("2017-04-30");
			
			$return = $a->GetFlights_today();
			if ($return)
			{
				echo "success getting flights<br />";
				$aResponse = $a->GetResponse();
 				$no_Flights = count ($aResponse) - 1; // last element is httpresponse...
 				if ($no_Flights > 0) {
          for ($i=0; $i<$no_Flights;$i++)
          {
            $starttimeVereinsflieger = new DateTime($aResponse[$i]["departuretime"]);
            $landingtimeVereinsflieger = new DateTime ($aResponse[$i]["arrivaltime"]);
						$pilotVereinsflieger = $aResponse[$i]["pilotname"];
						$nachname = substr($pilotVereinsflieger, 0, strpos($pilotVereinsflieger, ','));
						$vorname = substr($pilotVereinsflieger, strpos($pilotVereinsflieger, ',') + 2);
						$pilotVereinsflieger = $vorname . " " . $nachname;
						
						echo "Flug von Pilot: " . $pilotVereinsflieger . "<br />";

						if($pilotOLC == $pilotVereinsflieger)
						{
							echo "pilot found<br />";
							if (abs($starttimeOLC->getTimestamp() - $starttimeVereinsflieger->getTimestamp()) < 900) // 15 Minuten
							{
								echo "flight found<br />";
								return $aResponse[$i]["flid"];
							} else
							{
								echo "times not matching<br />";	
							}
						}
					}
					// no flight found
					echo "no flight found<br />";
					return -1;
				} else
				{
					// zero flights today
					echo "zero flights today<br />";
					return -2;
				}
				
			} else
			{
				// error when retrieving flights
				echo "error when retrieving flights<br />";
				return -2;
			}
			
			
		} else
		{
			// error in logging in to vereinsflieger
			echo "error in logging in to vereinsflieger<br />";
			return -3;
		}
		
	}


	function correctFlight ($flightid, $starttime, $landingtime)
	{
		global $vereinsfliegerLogin;
    global $vereinsfliegerPassword;
		
		echo "correctFlight()<br />";
		
    $Flight = array(
      'arrivaltime' => $landingtime->format("Y-m-d H:i"),
      'departuretime' => $starttime->format("Y-m-d H:i"));

    $a = new VereinsfliegerRestInterface();
    $result = $a->SignIn($vereinsfliegerLogin,$vereinsfliegerPassword,0);
		
		if ($result)
		{
			echo "success login<br />";
      $result = $a->UpdateFlight($flightid, $Flight);
	      
      if ($result)
      {
        echo "success adapting flight<br />";
				return $flightid;
	    } else
	    {
        echo "error: adapting flight<br />";
        return -1;
      }
		  } else
		  {
        echo "error: login<br />";
			  return -2;
		  }
		}
	

	function sendNotification($message, $userkey)
	{
		global $pushoverApplicationKey;
		
		curl_setopt_array($ch = curl_init(), array(
  			CURLOPT_URL => "https://api.pushover.net/1/messages.json",
  			CURLOPT_POSTFIELDS => array(
    		"token" => $pushoverApplicationKey,
    		"user" => $userkey,
    		"message" => $message,
  			),
  			CURLOPT_SAFE_UPLOAD => true,
		));
		curl_exec($ch);
		curl_close($ch);

	}


?>
