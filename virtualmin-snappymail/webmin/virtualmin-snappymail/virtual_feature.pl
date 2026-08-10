#!/usr/bin/perl
# Optional Virtualmin plugin feature API stub (future Server Template checkbox).
# Register as a Virtualmin plugin when ready; keep AUTO behaviour off by default.

use strict;
use warnings;

sub feature_name {
    return "SnappyMail webmail (web-only subserver)";
}

sub feature_losing {
    return "SnappyMail will be removed for this domain (application only by default).";
}

sub feature_label {
    return "Provision SnappyMail webmail subserver";
}

sub feature_check {
    # Always "available" if CLI exists.
    my $cli = $config{'cli_path'} || '/usr/local/bin/virtualmin-snappymail';
    return -x $cli ? undef : "virtualmin-snappymail CLI not installed";
}

sub feature_clash {
    return undef;
}

sub feature_depends {
    my ($d) = @_;
    return $d->{'mail'} ? undef : "Parent domain must have Mail enabled";
}

sub feature_setup {
    my ($d) = @_;
    return 1 if $d->{'parent'}; # only top-level
    &foreign_require('virtualmin-snappymail', 'virtualmin-snappymail-lib.pl');
    my ($ex, $out) = virtualmin_snappymail::run_cli('install', $d->{'dom'});
    &first_print("Provisioning SnappyMail for $d->{'dom'}");
    &second_print($ex ? "Failed: $out" : "OK");
    return !$ex;
}

sub feature_delete {
    my ($d) = @_;
    return 1 if $d->{'parent'};
    &foreign_require('virtualmin-snappymail', 'virtualmin-snappymail-lib.pl');
    my ($ex, $out) = virtualmin_snappymail::run_cli('remove', $d->{'dom'}, '--yes');
    &first_print("Removing SnappyMail for $d->{'dom'}");
    &second_print($ex ? "Failed: $out" : "OK");
    return !$ex;
}

1;
