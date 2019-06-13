;<?php
;die();
;/*
; Configuration for OLC2vereinsfliger

; Section for vereinsflieger settings
;
[vereinsflieger]
login_name = "abc@web.de"
password = "12345678"
appkey = "32894723847234897234be272"


; General settings valid for all flights
; - correctionExcludeList: Some loggers generate unprecise times. These are only used to generate new entrys, not for correction of existing ones.
; - enableOutput: enable output to HTML for development and testing
; 
[general]
timezone = "Europe/Zurich"
correctionExcludeList = "HB-1234,HB-5678"

; Section for general pushover settings. See also pilot configuration
; 
[pushover]
applicationkey = "sdmsdasdasdasd"
adminuserkey = "asdasasdasd"

;
;
; List of pilots. Enter the needed data in the arrays with the same order.
; - names: The name as it appears in OLC, without country code, Prename first e.g. "John Doe".
; - flighttypes: default flighttype ID to be used in vereinsflieger. 10 means N, "Privatflug".
; - starttypes: starttype to be used in vereinsflieger. "F" means glider tow, "E" means selflaunch.
; - chargemodes: chargemode ID to be used in vereinsflieger. "P" means pilot.
; - towplanes: callsign of the towplane to be entered. If no entry for the towplane should be created, use empty string.
; - gliders: callsign of the glider. Is only used if no callsign is found in OLC.
; - pushoveruserkeys: userkey of pushover for notifications. Leave empty if no notification should be sent to pilot.
;
[pilots]
names = "Glider Pilot,Second Pilot"
flighttypes = "10,10"
starttypes = "F,F"
chargemodes = "P,P"
towplanes = ","
gliders = "D-1234,D-1111"
pushoveruserkeys = "ddfsvsdsd,sdfdvvlö"


;*/

