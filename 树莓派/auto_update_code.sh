#!/bin/bash

CODE_BASE_DIR=/home/httpd/code
CODE=$1
HTAGS_OPS="-DfFnvahoIstx --fixed-guide --auto-completion"

function update_code()
{
	local code=$1
	local path=$PWD

	cd $CODE_BASE_DIR/$code
	git status | grep "Your branch is up to date" >/dev/null 2>&1
	if [ $? -eq 0 ]; then
		#sudo chown -R httpd:httpd ./HTML
		if [[ $code == "linux-next" ]]; then
			git fetch --all
			git reset --hard origin/master
		elif [[ $code == "linux-fs-next" ]]; then
			git fetch origin fs-next
			git reset --hard origin/fs-next
		else
	       		git pull >/dev/null
		fi
		#global -u
		gtags
		htags $HTAGS_OPS -t $1 -m 'start_kernel'
		#sudo chown -R apache:apache ./HTML
	fi
	cd $path
	return $ret
}

function __usage()
{
	echo "Usage $0 <code>"
	echo "all"
	for file in `ls $CODE_BASE_DIR`
	do
		echo "$file"
	done	
}

if [ $# -ne 1 ]; then
	__usage
	exit 1
fi

for file in `ls $CODE_BASE_DIR`
do
	if [ "$CODE" == "$file" ] || [ "$CODE" == "all" ]; then
		echo "Update $file"
		update_code $file &
	fi
done
wait
