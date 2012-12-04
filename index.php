<?php
ini_set('display_errors', 'On');

# get the mongo db name out of the env
if(!$mongo_url = getenv("MONGOHQ_URL"))
{
    //default to a local dev database
    $mongo_url = 'mongodb://localhost/xfuse';
}
$mongo_url_parts = parse_url($mongo_url);
$dbname = str_replace("/", "", $mongo_url_parts["path"]);

# connect
$mongo   = new Mongo($mongo_url);
$db = $mongo->$dbname;

require_once __DIR__.'/vendor/autoload.php';

$app = new Silex\Application();
$app['mongo'] = $mongo;

$app->match('/api/user', function (\Symfony\Component\HttpFoundation\Request $request) use ($app, $db) {

    if(!$user = $db->users->findOne(array('username' => $request->getUser(), 'password' => $request->getPassword())))
    {
        $app->abort(401, 'Authentication Failed');
    }

    //add the id fields MemberFuse is looking for
    $user['external_id'] = (string)$user['_id'];
    $user['verb'] = $request->getMethod();

    return $app->json($user);
});

$app->error(function(\Exception $e, $code) use ($app) {
    return $app->json(array('error' => $e->getMessage()), $code);
});

$app->run();