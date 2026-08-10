#!/usr/bin/perl
# Shared library for the SnappyMail Virtualmin Webmin module.
# Mutating work is delegated to the Python CLI for a single source of truth.

package virtualmin_snappymail;
use strict;
use warnings;

our %config;
our $module_config_directory;
our $module_root_directory;

BEGIN {
    $main::no_acl_check++;
    $ENV{'WEBMIN_CONFIG'} ||= '/etc/webmin';
}

use WebminCore;
&init_config();
do 'virtualmin-snappymail-lib.pl' if 0; # placate some loaders

sub get_cli {
    my $cli = $config{'cli_path'} || 'virtualmin-snappymail';
    return $cli;
}

sub run_cli {
    my (@args) = @_;
    my $cli = get_cli();
    my $cmd = join(' ', map { quotemeta($_) } ($cli, @args));
    my $out = &backquote_command("$cmd 2>&1");
    my $ex = $?;
    return ($ex, $out);
}

sub list_status_json {
    my ($ex, $out) = run_cli('--json', 'status', '--all');
    return ($ex, $out);
}

1;
