<?php
echo "Testing Mongo Connection...";

# get the mongo db name out of the env
if(!$mongo_url = getenv("MONGOHQ_URL"))
{
    //default to a local dev database
    $mongo_url = 'mongodb://localhost/xfuse';
}
$mongo_url_parts = parse_url($mongo_url);
$dbname = str_replace("/", "", $mongo_url_parts["path"]);

# connect
$m   = new Mongo($mongo_url);