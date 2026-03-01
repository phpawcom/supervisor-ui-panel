#!/usr/bin/perl
# WHM Plugin Entry Point
# Location: /usr/local/cpanel/whostmgr/docroot/cgi/supervisormanager/index.cgi
#
# WHM calls this CGI script when the admin visits the plugin.
# It validates the WHM session, generates an admin token, and redirects
# to the Laravel-powered WHM admin interface.

use strict;
use warnings;
use Cpanel::Form          ();
use Whostmgr::ACLS        ();
use Whostmgr::HTMLInterface ();

Whostmgr::ACLS::init_acls();

# Verify the user has access
unless ( Whostmgr::ACLS::hasroot() || Whostmgr::ACLS::checkacl('all') ) {
    print "Content-type: text/html\r\n\r\n";
    print "<html><body><h2>Access Denied</h2><p>You need root or reseller access to use this plugin.</p></body></html>";
    exit;
}

my $remote_user   = $ENV{'REMOTE_USER'}   || '';
my $security_hash = $ENV{'WHM_ACCESSHASH'} || '';

# The Laravel plugin API base
my $api_base = '/usr/local/cpanel/3rdparty/laravel_supervisor_plugin/public';
my $plugin_url = '/cpanel-plugins/supervisormanager/whm/index.php';

# Generate a short-lived admin token via the plugin's CLI
my $token = generate_admin_token($remote_user);

if ($token) {
    # Redirect to the Laravel WHM interface
    print "Location: ${plugin_url}?_admin_token=" . uri_escape($token) . "\r\n\r\n";
} else {
    print "Content-type: text/html\r\n\r\n";
    Whostmgr::HTMLInterface::defheader('Supervisor Manager Error');
    print '<div class="bodyContent"><p class="error">Failed to generate authentication token. ';
    print 'Please check the plugin installation.</p></div>';
    Whostmgr::HTMLInterface::deffooter();
}

sub generate_admin_token {
    my ($user) = @_;
    my $php_bin = '/usr/bin/php';
    my $helper  = '/usr/local/cpanel/3rdparty/laravel_supervisor_plugin/scripts/generate_token.php';

    return '' unless -x $php_bin && -f $helper;

    my $token = `$php_bin $helper whm_admin $user 2>/dev/null`;
    chomp $token;
    return $token;
}

sub uri_escape {
    my ($str) = @_;
    $str =~ s/([^A-Za-z0-9\-_.~])/sprintf("%%%02X", ord($1))/ge;
    return $str;
}
