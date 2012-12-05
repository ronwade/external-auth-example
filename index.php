<?php
ini_set('display_errors', 'On');

# get the mongo db name out of the env
# this is set in the heroku environment to specify the db
if(!$mongo_url = getenv("MONGOHQ_URL"))
{
    //default to a local dev database
    $mongo_url = 'mongodb://localhost/xfuse';
}
$mongo_url_parts = parse_url($mongo_url);
$dbname = str_replace("/", "", $mongo_url_parts["path"]);

# connect to the database
$mongo   = new Mongo($mongo_url);
$db = $mongo->$dbname;

//use the autoloader for the composer installed dependencies
require_once __DIR__.'/vendor/autoload.php';

//create our application and give it the mongo connection
$app = new Silex\Application();
$app['mongo'] = $mongo;

/**
 * The RESTful user endpoint
 * Authenticates a user via either a Bearer access token or the users credentials
 * If valid authentication, a json representation of the user is returned in the response
 */
$app->get('/api/user', function (\Symfony\Component\HttpFoundation\Request $request) use ($app, $db) {

    $user = null;


    //if an access token was presented, attempt to authenticate with that
    //this could have been from the SSO workflow with MemberFuse
    if($request->headers->has('authorization'))
    {
        //split the header value into its parts... Bearer and the actual token
        $auth = explode(' ',$request->headers->get('authorization'));
        if($auth[0] == 'Bearer')
        {
            $token = $auth[1];

            //look for the token in our db. this will have been
            //created during the last step of the SSO workflow
            if(!$token = $db->tokens->findOne(array('token' => $token)))
            {
                $app->abort(401, 'invalid_token');
            }

            //tokens are associated to a user so use the token to find the
            //correct user to return
            if(!$user = $db->users->findOne(array('_id' => $token['user_id'])))
            {
                $app->abort(401, 'unauthorized_token');
            }
        }
        else
        {
            $app->abort(400, 'invalid authorization type. must be Bearer ' . $auth[0] . ' given');
        }
    }

    //if above didn't produce a user try with user credentials from the basic auth
    //This is supporting the External Authentication function
    if(!$user)
    {
        if(!$user = $db->users->findOne(array('username' => $request->getUser(), 'password' => $request->getPassword())))
        {
            $app->abort(401, 'user credentials authentication failed');
        }
    }

    //add the id fields MemberFuse is looking for
    //NOTE when using the external_id field, it is up to you to make sure
    //the external_id is mapped in memberfuse.  This can be done via the REST api on MemberFuse when creating or
    //updating users
    $user['external_id'] = (string)$user['_id'];

    //NOTE: above is just for example.  For this app, the record of the user in the db will have an mf_id
    //field that we just arbitrarily set.  This value takes precedence and that user will be logged into MemberFuse

    //Our example app returns JSON but you can also return XML here if you set the Content-Type header appropriately
    return $app->json($user);
});


/**
 * The OAuth 2.0 Authorization endpoint for the SSO workflow
 * This page presents a login form for the user to login with.  This is where MemberFuse will redirect users
 * to for SSO.  They will login here and be redirected back to MemberFuse with a code.
 *
 * NOTE: for simplicity, this page doesn't check if the user is already logged in.  In a real system you would do so.
 */
$app->match('/oauth/authorize', function(\Symfony\Component\HttpFoundation\Request $request) use ($app, $db){

    //the login form was submitted
    if($request->isMethod("POST"))
    {
        //validate the user by attempting to load them from the db
        if(!$user = $db->users->findOne(array('username'=>$request->request->get('username'), 'password'=>$request->request->get('password'))))
        {
            return "Invalid Login";
        }

        //check if we came here from an OAuth client (MemberFuse)
        //the redirect_uri would be set in the query string if so
        if($redirect_uri = $request->query->get('redirect_uri'))
        {
            //generate an authorization code
            //How you do this is up to you.  You should use a technique more random and secure than below
            $auth_code = sha1(uniqid());

            //save the code associated with the user
            //this will be retrieved by the token endpoint
            $db->auth_codes->save(array('code'=>$auth_code, 'user_id' => $user['_id']));

            //append our code to the redirect uri
            //be careful to respect if the redirect uri already had a query string component
            if(parse_url($redirect_uri, PHP_URL_QUERY))
            {
                $redirect_uri .= '&';
            }
            else
            {
                $redirect_uri .= '?';
            }

            $redirect_uri .= 'code=' . urldecode($auth_code);

            //MemberFuse will also send a "state" parameter when redirecting the user
            //you must send the exact state back.  This is to help against CSRF attacks
            $redirect_uri .= '&state=' . urlencode($request->query->get('state'));

            //send the user along
            return $app->redirect($redirect_uri);
        }

        //the user must have come here on their own, just welcome them :)
        return "Welcome " . $user['firstname'];

    }

        //Render a very simple page with a login form
$html = <<< HTML
<html>
    <head>
        <title>Login</title>
    </head>
    <body>
        <p>Please login below</p>
        <form method="POST">
            <label>Username</label>
            <input type="text" name="username" />
            <label>Password</label>
            <input type="password" name="password" />
            <input type="submit" value="Login" />
        </form>
    </body>
</html>
HTML;

    return $html;

})
->method("GET|POST");

/**
 * OAuth Token endpoint
 * RESTful endpoint to retrieve an access token for a user.  MemberFuse will call this endpoint
 * with the authorization code returned to it from the above url.  This token will be used to
 * fetch the appropriate user to login
 */
$app->post('/oauth/token', function(\Symfony\Component\HttpFoundation\Request $request) use ($app, $db){

    //grab the code from the posted vars
    if(!$code = $request->request->get('code'))
    {
        $app->abort(400, 'invalid_request');
    }

    //load the code from the db. it was saved during the authorization step
    if(!$code = $db->auth_codes->findOne(array('code'=>$code)))
    {
        return $app->abort(400, 'invalid_grant');
    }

    //load the user associated with the code
    $user = $db->users->findOne(array('_id' => $code['user_id']));

    //generate an access token
    //the algorithm you use is up to you.  You should do something more random and less predictable
    //than this though
    $token = sha1(uniqid());

    //save the token associated with the user from the auth code
    $db->tokens->save(array('token'=>$token, 'user_id'=>$user['_id']));

    //manually sending the json header as a work around to a bug with the Silex framework
    //being used in this demo app
    header('Content-Type: application/json');

    //return a json response with the access token.  This must conform to the OAuth2.0 spec
    return $app->json(array(
        'access_token' => $token,
        'token_type' => 'example',
    ),200);

});

/**
 * Error handler
 * Outputs errors in json form for this demo app
 */
$app->error(function(\Exception $e, $code) use ($app) {
    return $app->json(array('error' => $e->getMessage()), $code);
});

//run the app
$app->run();