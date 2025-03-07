#!/bin/bash
# @package   DPDocker
# @copyright Copyright (C) 2020 Digital Peak GmbH. <https://www.digital-peak.com>
# @license   http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL

db=${db:-mysql}
pg=${pg:-latest}
my=${my:-latest}
php=${php:-latest}
e=${e:-}
t=${t:-}
j=${j:-4}
b=${b:-chrome}

while [ $# -gt 0 ]; do
	 if [[ $1 == "-"* ]]; then
		param="${1/-/}"
		declare $param="$2"
	 fi
	shift
done

if [ -z $e ]; then
	echo "No extension found!"
	exit
fi

if [ ! -d $(dirname $0)/www ]; then
	mkdir $(dirname $0)/www
fi

# Stop the containers
if [ -z $t ]; then
	docker-compose -f $(dirname $0)/docker-compose.yml stop
fi

# Run VNC viewer
if [[ $(command -v vinagre) && -z $t ]]; then
	( sleep 15; vinagre localhost > /dev/null 2>&1 ) &
fi
if [[ $(command -v vinagre) && ! -z $t ]]; then
	( sleep 3; vinagre localhost > /dev/null 2>&1 ) &
fi

# Start web server already so it gets the updated variables
EXTENSION=$e TEST=$t JOOMLA=$j DB=$db MYSQL_DBVERSION=$my POSTGRES_DBVERSION=$pg PHP_VERSION=$php BROWSER=$b docker-compose -f $(dirname $0)/docker-compose.yml up -d web-test

# Run the tests
EXTENSION=$e TEST=$t JOOMLA=$j DB=$db MYSQL_DBVERSION=$my POSTGRES_DBVERSION=$pg PHP_VERSION=$php BROWSER=$b docker-compose -f $(dirname $0)/docker-compose.yml run system-tests

# Stop the containers
if [ -z $t ]; then
	docker-compose -f $(dirname $0)/docker-compose.yml stop
fi
