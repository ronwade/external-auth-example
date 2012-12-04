<?php
echo "Testing Mongo Connection...";

# get the mongo db name out of the env
$mongo_url = parse_url(getenv("MONGO_URL"));
$dbname = str_replace("/", "", $mongo_url["path"]);

# connect
$m   = new Mongo(getenv("MONGO_URL"));