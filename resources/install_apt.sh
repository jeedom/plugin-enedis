PROGRESS_FILE=/tmp/dependancy_enedis_in_progress
if [ ! -z $1 ]; then
	PROGRESS_FILE=$1
fi
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "********************************************************"
echo "*        Launch install of Enedis dependencies         *"
echo "********************************************************"
sudo apt-get update
echo 50 > ${PROGRESS_FILE}
sudo apt install -o Dpkg::Options::="--force-confdef" -y php-mbstring
echo 100 > ${PROGRESS_FILE}
echo "********************************************************"
echo "*     Enedis dependencies successfully installed!      *"
echo "********************************************************"
rm ${PROGRESS_FILE}
