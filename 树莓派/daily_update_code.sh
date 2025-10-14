#!/bin/bash

sleep 13h
while true; do
	./auto_update_code.sh all > /dev/null 2>&1 &
	sleep 1d
done
