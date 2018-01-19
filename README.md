# OLC2Vereinsflieger

This script receives start and landing time information from a OLCnotifier (https://github.com/niggli/OLCnotifier)
and searches for matching flights in vereinsflieger.de. If it finds a matching flight, it corrects it's timestamps.
If it doesn't find a matching flight, it adds a new one to vereinsflieger.de. Towplane times are not touched.