<?php 

	// OLC to Vereinsflieger
	//
	// This script receives start and landing time information from a OLCnotifier (https://github.com/niggli/OLCnotifier)
	// and searches for matching flights in vereinsflieger.de. If it finds a matching flight, it corrects it's timestamps.
	//
	// Versions
	// 1.0 - 31.05.2017 First draft
	// 1.1 - 15.06.2017 Add some fields, improve error handling
	// 2.0 - 15.09.2017 Implement adding of new flights, implement usage of date/callsign/airfield
	

	// Enable error output
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
	
	// global constants ##TODO config file
	$vereinsfliegerLogin = "";
	$vereinsfliegerPassword = "";
	$pushoverApplicationKey = "";
	$pushoverUserKey = "";
	$newflightsStarttype = "F";
	$newflightsFlighttypeID = "10"; // 10 means N, Privatflug
	$newflightsChargemode = "2"; // 2 means P, Pilot
	$newflightsTowplane = ""; // leave empty if no tow entry should be created
	$correctionExcludeList = array("callsign1", "callsign2");
	
  require_once('VereinsfliegerRestInterface.php');	
  date_default_timezone_set ( "UTC");

	// echo header
	echo "<html><head></head><body><h1>OLCtoVereinsflieger</h1>";

	// check passed variables
	if (   (isset ($_GET['starttime']))
		  && (isset ($_GET['landingtime']))
		  && (isset ($_GET['pilotname'])) 
			&& (isset ($_GET['airfield'])) 
			&& (isset ($_GET['callsign'])) )
	{
		$starttimeFromOLC = new DateTime($_GET['starttime']);
		$landingtimeFromOLC = new DateTime($_GET['landingtime']);
		$pilotnameFromOLC = $_GET['pilotname'];
		$airfieldFromOLC = $_GET['airfield'];
		$callsignFromOLC = $_GET['callsign'];
		
		echo "Start time: " . $starttimeFromOLC->format('Y-m-d H:i:s') .  "<br />";
		echo "Landing time: " . $landingtimeFromOLC->format('Y-m-d H:i:s') . "<br />";
		echo "Pilot name: " . $pilotnameFromOLC . "<br />";
		echo "Airfield: " . $airfieldFromOLC . "<br />";
		echo "Callsign: " . $callsignFromOLC . "<br />";
		
		$flightidVereinsflieger = findFlightID($starttimeFromOLC, $pilotnameFromOLC);
		
		if ($flightidVereinsflieger > 0)
		{
			// matching flight found, correct times
			$result = correctFlight($flightidVereinsflieger, $starttimeFromOLC, $landingtimeFromOLC, $callsignFromOLC);
			if ($result > 0)
			{
				sendNotification("Flight of " . $pilotnameFromOLC . " corrected." , $pushoverUserKey);
			} else
			{
				sendNotification("Error correcting flight. Errorcode " . $result, $pushoverUserKey);
			}
		} else
		{
			// no matching flight found, create new
			$result = addFlight($starttimeFromOLC, $landingtimeFromOLC, $pilotnameFromOLC, $airfieldFromOLC, $callsignFromOLC);
			if ($result > 0)
			{
				sendNotification("Flight of " . $pilotnameFromOLC . " imported from OLC." , $pushoverUserKey);
			} else
			{
				sendNotification("Error adding flight. Errorcode " . $result, $pushoverUserKey);
			}
		}

	} else
	{
		// nothing set, startpage
		echo "Called with not all parameters, no functionality.";
	}

	function findFlightID ($starttimeOLC, $pilotOLC)
	{
		echo "findFlightID()<br />";
		
		global $vereinsfliegerLogin;
		global $vereinsfliegerPassword;
		
		// login to Vereinsflieger
		$a = new VereinsfliegerRestInterface();
		$result = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, 0);
		
		if ($result)
		{			
			echo "success login<br />";
	
			// Get all flights from date of flight
			$datum = date_format($starttimeOLC, "Y-m-d");
			$return = $a->GetFlights_date ($datum);

			if ($return)
			{
				echo "success getting flights<br />";
				$aResponse = $a->GetResponse();
 				$no_Flights = count ($aResponse) - 1; // last element is httpresponse...
 				if ($no_Flights > 0)
 				{
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
							if (abs($starttimeOLC->getTimestamp() - $starttimeVereinsflieger->getTimestamp()) < 900) // 15 minutes tolerance
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
				return -3;
			}
			
			
		} else
		{
			// error in logging in to vereinsflieger
			echo "error in logging in to vereinsflieger<br />";
			return -4;
		}
		
	}


	function correctFlight ($flightid, $starttime, $landingtime, $callsign)
	{
		global $vereinsfliegerLogin;
    global $vereinsfliegerPassword;
		global $correctionExcludeList;
		
		echo "correctFlight()<br />";
		
		// Check if plane is in exclude list because it's logger doesn't produce exact start/landing times
		if ( in_array($callsign, $correctionExcludeList) )
		{
			echo "Don't correct flight, plane is in exclude list<br />";
			return -3;
		} else
		{
			$Flight = array(
      	'arrivaltime' => $landingtime->format("Y-m-d H:i"),
      	'departuretime' => $starttime->format("Y-m-d H:i"));

    	$a = new VereinsfliegerRestInterface();
    	$result = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, 0);
		
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
		
    
	}
	
	function addFlight ($starttime, $landingtime, $pilotname, $airfield, $callsign)
	{
		global $vereinsfliegerLogin;
		global $vereinsfliegerPassword;
		global $newflightsStarttype;
		global $newflightsFlighttypeID;
		global $newflightsChargemode;
		global $newflightsTowplane;
		
		echo "addFlight()<br />";	
		
		// Pilot name in vereinsflieger must be "Nachname, Vorname", but in OLC it's "Vorname Nachname" (country code is already removed in OLCnotifier)
		$vorname = substr($pilotname, 0, strrpos($pilotname, ' '));
		$nachname = substr($pilotname, strrpos($pilotname, ' '), strlen($pilotname) - 1);
		$pilotname = $nachname . ", " . $vorname;
		
		echo "pilotname converted to Vereinsflieger Format: $pilotname<br />";		
		
		$Flight = array(
		  'callsign' => $callsign,
		  'pilotname' => $pilotname,
		  'starttype' => $newflightsStarttype,
		  'departurelocation' => $airfield,
		  'arrivallocation' => $airfield,
		  'ftid' => $newflightsFlighttypeID,
		  'chargemode' => $newflightsChargemode,
		  'towcallsign' => $newflightsTowplane,
		  'comment' => "Flug wurde aus OLC importiert, bitte alle Angaben prüfen",
		  'arrivaltime' => $landingtime->format("Y-m-d H:i"),
		  'departuretime' => $starttime->format("Y-m-d H:i"));
      

    $a = new VereinsfliegerRestInterface();
    $result = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, 0);
		
		if ($result)
		{
			echo "success login<br />";
      $result = $a->InsertFlight($Flight);
	      
      if ($result)
      {
        echo "success adding flight<br />";
				return 1;
	    } else
	    {
        echo "error: adding flight<br />";
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
		
		if (  ($userkey != "")
			 && ($pushoverApplicationKey != "") )
		{
			$message = "OLC2Vereinsflieger: " . $message;
		
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
		} else
		{
			echo "No notification sent<br />";
		}
		
	}


?>
