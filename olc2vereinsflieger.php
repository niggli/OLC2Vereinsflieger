<?php 

  // OLC to Vereinsflieger
  //
  // This script receives start and landing time information from a OLCnotifier (https://github.com/niggli/OLCnotifier)
  // and searches for matching flights in vereinsflieger.de. If it finds a matching flight, it corrects it's timestamps.
  // If it doesn't find a matching flight, it adds a new one to vereinsflieger.de.
  // Towplane times are not touched.
  //
  // Versions
  // 1.0 - 31.05.2017 First draft
  // 1.1 - 15.06.2017 Add some fields, improve error handling
  // 2.0 - 15.09.2017 Implement adding of new flights, implement usage of date/callsign/airfield
  // 2.1 - 19.01.2018 Implement timezone support
  // 2.2 - 08.02.2018 Implement pilot list filter
  // 2.3 - 15.04.2018 Inform admin when called with not all parameters
  // 2.4 - 25.01.2019 Possibility to run locally via CLI
  // 2.5 - 11.06.2019 Add Appkey for vereinsflieger login


  // Prepare array for pilot list
  $pilotentry = array (
    "name" => "",
    "flighttype" => "",
    "starttype" => "",
    "chargemode" => "",
    "towplane" => "",
    "glider" => "",
    "pushoveruserkey" => ""
  );
  $pilots = array();
  
  // read configuration
  $configuration = parse_ini_file ("olc2vereinsflieger.cfg.php", 1);
  $vereinsfliegerLogin = $configuration["vereinsflieger"]["login_name"];
  $vereinsfliegerPassword = $configuration["vereinsflieger"]["password"];
  $vereinsfliegerClub = $configuration["vereinsflieger"]["cid"];
  $vereinsfliegerAppkey = $configuration["vereinsflieger"]["appkey"];
  $flightTimezone = $configuration["general"]["timezone"];
  $pushoverApplicationKey = $configuration["pushover"]["applicationkey"];
  $pushoverAdminUserKey = $configuration["pushover"]["adminuserkey"];
  $correctionExcludeList = explode (",",$configuration["general"]["correctionExcludeList"]);
  $pilotNames = explode (",",$configuration["pilots"]["names"]);
  $pilotFlighttypes = explode (",",$configuration["pilots"]["flighttypes"]);
  $pilotStarttypes = explode (",",$configuration["pilots"]["starttypes"]);
  $pilotChargemodes = explode (",",$configuration["pilots"]["chargemodes"]);
  $pilotTowplanes = explode (",",$configuration["pilots"]["towplanes"]);
  $pilotGliders = explode (",",$configuration["pilots"]["gliders"]);
  $pilotPushoverkeys = explode (",",$configuration["pilots"]["pushoveruserkeys"]);

  for ($i = 0; $i < count($pilotNames); $i++)
  {
    $pilots[$i] = $pilotentry;
    $pilots[$i]["name"] = $pilotNames[$i];
    $pilots[$i]["flighttype"] = $pilotFlighttypes[$i];
    $pilots[$i]["starttype"] = $pilotStarttypes[$i];
    $pilots[$i]["chargemode"] = $pilotChargemodes[$i];
    $pilots[$i]["towplane"] = $pilotTowplanes[$i];
    $pilots[$i]["glider"] = $pilotGliders[$i];
    $pilots[$i]["pushoveruserkey"] = $pilotPushoverkeys[$i];
  }
  
  // Enable error output
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
  
  require_once('VereinsfliegerRestInterface.php');  
  date_default_timezone_set("UTC");

  // echo header
  echo "<html><head></head><body><h1>OLCtoVereinsflieger</h1>";
  
  // Get arguments from commandline if called locally (for development)
  if ('cli' === PHP_SAPI) {
    
    $starttimeFromOLC =new DateTime($argv[1]);
    $landingtimeFromOLC = new DateTime($argv[2]);
    $pilotnameFromOLC = $argv[3];
    $airfieldFromOLC = $argv[4];
    $callsignFromOLC = $argv[5];
    
  } else
  {
    
    $starttimeFromOLC = new DateTime($_GET['starttime']);
    $landingtimeFromOLC = new DateTime($_GET['landingtime']);
    $pilotnameFromOLC = $_GET['pilotname'];
    $airfieldFromOLC = $_GET['airfield'];
    $callsignFromOLC = $_GET['callsign'];
    
  }

  // check passed variables
  if ( (isset ($starttimeFromOLC))
    && (isset ($landingtimeFromOLC))
    && (isset ($pilotnameFromOLC)) 
    && (isset ($airfieldFromOLC)) 
    && (isset ($callsignFromOLC)) )
  {
   
    echo "Start time(UTC): " . $starttimeFromOLC->format('Y-m-d H:i:s') .  "<br />";
    echo "Landing time(UTC): " . $landingtimeFromOLC->format('Y-m-d H:i:s') . "<br />";
    echo "Pilot name: " . $pilotnameFromOLC . "<br />";
    echo "Airfield: " . $airfieldFromOLC . "<br />";
    echo "Callsign: " . $callsignFromOLC . "<br />";
    
    // check if pilot name is configured in list
    $pilotindex = findPilot($pilotnameFromOLC);

    if ($pilotindex >= 0)
    {
      
      $flightidVereinsflieger = findFlightID($starttimeFromOLC, $pilots[$pilotindex]["name"]);
      
      if ($flightidVereinsflieger > 0)
      {
        // matching flight found, correct times
        $result = correctFlight($flightidVereinsflieger, $starttimeFromOLC, $landingtimeFromOLC, $callsignFromOLC);
        if (is_numeric($result))
        {
          sendNotification("Flug mit Daten aus OLC korrigiert. Start: " . $starttimeFromOLC->format('Y-m-d H:i:s') . " Landung: " . $landingtimeFromOLC->format('Y-m-d H:i:s'), $pilots[$pilotindex]["pushoveruserkey"]);
          if ($pilots[$pilotindex]["pushoveruserkey"] != $pushoverAdminUserKey)
          {
            sendNotification("Flug von " . $pilotnameFromOLC . " korrigiert." , $pushoverAdminUserKey);
          }
        } else
        {
          sendNotification("Fehler beim Korrigieren: " . $result, $pushoverAdminUserKey);
        }
      } else
      {
        // no matching flight found, create new
        $result = addFlight($starttimeFromOLC, $landingtimeFromOLC, $airfieldFromOLC, $callsignFromOLC, $pilots[$pilotindex]);
        if (is_numeric($result))
        {
          sendNotification("Flug aus OLC importiert. Start: " . $starttimeFromOLC->format('Y-m-d H:i:s') . " Landung: " . $landingtimeFromOLC->format('Y-m-d H:i:s'), $pilots[$pilotindex]["pushoveruserkey"]);
          if ($pilots[$pilotindex]["pushoveruserkey"] != $pushoverAdminUserKey)
          {
            sendNotification("Flug von " . $pilotnameFromOLC . " importiert." , $pushoverAdminUserKey);
          }
        } else
        {
          sendNotification("Fehler beim Erzeugen: " . $result, $pushoverAdminUserKey);
        }
      }
    } else
    {
      echo "Pilotfilter active, pilot not in list <br />"; 
    }/* if pilot in list */

  } else
  {
    sendNotification("Called with not all parameters, no functionality.", $pushoverAdminUserKey);
  }
  
  // echo end of HTML
  echo "</body></html>";

  function findFlightID ($starttimeOLC, $pilotOLC)
  {    
    global $vereinsfliegerLogin;
    global $vereinsfliegerPassword;
    global $vereinsfliegerClub;
    global $vereinsfliegerAppkey;
    global $flightTimezone;
    
    echo "findFlightID()<br />";
    
    // login to Vereinsflieger
    $a = new VereinsfliegerRestInterface();
    $success = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, $vereinsfliegerClub, $vereinsfliegerAppkey);
    
    if ($success)
    {      
      echo "success login<br />";
  
      // Get all flights from date of flight
      $datum = date_format($starttimeOLC, "Y-m-d");
      $success = $a->GetFlights_date ($datum);

      if ($success)
      {
        echo "success getting flights<br />";
        $aResponse = $a->GetResponse();
        $no_Flights = count ($aResponse) - 1; // last element is httpresponse...
        if ($no_Flights > 0)
        {
          for ($i=0; $i<$no_Flights;$i++)
          {
            $daydate = $starttimeOLC;
            $starttimeVereinsflieger = timestring_to_utc($aResponse[$i]["departuretime"], $daydate, $flightTimezone);
            $landingtimeVereinsflieger = timestring_to_utc($aResponse[$i]["arrivaltime"], $daydate, $flightTimezone);
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
                echo "starttime: " . $starttimeOLC->getTimestamp() . "<br />";
                $temp = $starttimeVereinsflieger->getTimestamp();
                echo "starttime vereinsflieger: " . $temp . "<br />";
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
    global $vereinsfliegerClub;
    global $vereinsfliegerAppkey;
    global $correctionExcludeList;
    global $flightTimezone;
    
    echo "correctFlight()<br />";
    
    // Check if plane is in exclude list because it's logger doesn't produce exact start/landing times
    if ( in_array($callsign, $correctionExcludeList) )
    {
      echo "Don't correct flight, plane is in exclude list<br />";
      return "Plane is in exlude list.";
    } else
    {
      $Flight = array(
        'arrivaltime'   => datetime_to_local($landingtime, $flightTimezone)->format("Y-m-d H:i"),
        'departuretime' => datetime_to_local($starttime, $flightTimezone)->format("Y-m-d H:i"));
      
      $a = new VereinsfliegerRestInterface();
      $success = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, $vereinsfliegerClub, $vereinsfliegerAppkey);
    
      if ($success)
      {
        echo "success login<br />";
        $success = $a->UpdateFlight($flightid, $Flight);
          
        if ($success)
        {
          echo "success adapting flight<br />";
          return $flightid;
        } else
        {
          echo "error: adapting flight<br />";
          return "Error adapting flight in vereinsflieger.";
        }
      } else
      {
        echo "error: login<br />";
        return "Error logging into vereinsflieger.";
      }
    }    
  }
  
  function addFlight ($starttime, $landingtime, $airfield, $callsign, $pilot)
  { 
    global $vereinsfliegerLogin;
    global $vereinsfliegerPassword;
    global $vereinsfliegerClub;
    global $vereinsfliegerAppkey;
    global $flightTimezone;
    
    echo "addFlight()<br />";  
    
    // Pilot name in vereinsflieger must be "Nachname, Vorname", but in OLC it's "Vorname Nachname" (country code is already removed in OLCnotifier)
    $vorname = substr($pilot["name"], 0, strrpos($pilot["name"], ' '));
    $nachname = substr($pilot["name"], strrpos($pilot["name"], ' '), strlen($pilot["name"]) - 1);
    $pilotname = $nachname . ", " . $vorname;
    
    echo "pilotname converted to Vereinsflieger Format: $pilotname<br />";  
    
    if ($callsign == "")
    {
      $callsign = $pilot["glider"];
    }
    
    $Flight = array(
      'callsign' => $callsign,
      'pilotname' => $pilotname,
      'starttype' => $pilot["starttype"],
      'departurelocation' => $airfield,
      'arrivallocation' => $airfield,
      'ftid' => $pilot["flighttype"],
      'chargemode' => $pilot["chargemode"],
      'towcallsign' => $pilot["towplane"],
      'comment' => "Flug aus OLC importiert",
      'arrivaltime' => datetime_to_local($landingtime, $flightTimezone)->format("Y-m-d H:i"),
      'departuretime' => datetime_to_local($starttime, $flightTimezone)->format("Y-m-d H:i"));
      

    $a = new VereinsfliegerRestInterface();
    $success = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, $vereinsfliegerClub, $vereinsfliegerAppkey);
    
    if ($success)
    {
      echo "success login<br />";
      $success = $a->InsertFlight($Flight);
        
      if ($success)
      {
        echo "success adding flight<br />";
        return 1;
      } else
      {
        echo "error: adding flight<br />";
        return "Error adding flight in vereinsflieger.";
      }
    } else
    {
      echo "error: login<br />";
      return "Error logging into vereinsflieger.";
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
  
  
  // Converts a string containing a time (hh:mm:ss) and a date object to a UTC datetime object
  function timestring_to_utc($timestring, $date, $timezone)
  {
    // Create date object
    $timestamp_lcl = new DateTime($date->format("Ymd") . "T" . $timestring, new DateTimeZone($timezone));
    
    // Convert to UTC
    $timestamp_utc = $timestamp_lcl->setTimezone(new DateTimeZone("UTC"));
    
    // Create string and return
    if ($timestamp_utc != FALSE)
    {
      return $timestamp_utc;
    } else
    {
      return -1;
    }
  }
  
  // Converts a datetime object to a localtime datetime object
  function datetime_to_local($timestamp_utc, $timezone)
  {
    // Convert to localtime
    $timestamp_lcl = $timestamp_utc->setTimezone(new DateTimeZone($timezone));
    
    // Create string and return
    if ($timestamp_lcl != FALSE)
    {
      return $timestamp_lcl;
    } else
    {
      return -1;
    }
  }
  
  function findPilot($pilotnameFromOLC)
  {
    global $pilots;
    
    for ($j = 0; $j < count($pilots); $j++)
    {
      if ($pilots[$j]["name"] == $pilotnameFromOLC)
      {
        return $j;        
      }
    }
    
    return -1;
  }

?>
