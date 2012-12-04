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

$app->get('/api/user', function (\Symfony\Component\HttpFoundation\Request $request) use ($app, $db) {

    $user = null;

    //if an access token was presented, attempt to authenticate with that
    //this could have been from the SSO workflow with MemberFuse
    if($request->headers->has('authorization'))
    {
        $auth = explode(' ',$request->headers->get('authorization'));
        if($auth[0] == 'Bearer')
        {
            $token = $auth[1];
            $token = $db->tokens->findOne(array('token' => $token));
            $user = $db->users->findOne(array('_id' => $token['user_id']));
        }
    }

    //if above didn't produce a user try with user credentials from the basic auth
    if(!$user)
    {
        if(!$user = $db->users->findOne(array('username' => $request->getUser(), 'password' => $request->getPassword())))
        {
            $app->abort(401, 'Authentication Failed');
        }
    }

    //add the id fields MemberFuse is looking for
    $user['external_id'] = (string)$user['_id'];

    return $app->json($user);
});

$app->error(function(\Exception $e, $code) use ($app) {
    return $app->json(array('error' => $e->getMessage()), $code);
});

$app->run();