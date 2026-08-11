#!/usr/bin/perl
# Optional Virtualmin plugin feature: SnappyMail as a managed web-only webapp.
# Register under System Settings → Features and Plugins.
# Mutating work always goes through the Python CLI.

use strict;
use warnings;
our (%text, %config, $module_name);

require 'virtualmin-snappymail-lib.pl';

sub feature_name {
	return $text{'feat_name'} || 'SnappyMail webmail';
}

sub feature_losing {
	return $text{'feat_losing'}
		|| 'SnappyMail application will be removed for this domain.';
}

sub feature_disname {
	return $text{'feat_disname'}
		|| 'SnappyMail webmail management will be disabled.';
}

sub feature_label {
	my ($edit) = @_;
	return $edit
		? ($text{'feat_label2'} || 'SnappyMail webmail (web-only subserver)')
		: ($text{'feat_label'} || 'Provision SnappyMail webmail subserver');
}

sub feature_hlink {
	return 'label';
}

sub feature_check {
	return &cli_available();
}

sub feature_depends {
	my ($d) = @_;
	return $text{'feat_edepmail'} || 'Parent domain must have Mail enabled'
		if !$d->{'mail'};
	return undef;
}

sub feature_clash {
	return undef;
}

sub feature_suitable {
	my ($parentdom, $aliasdom, $subdom) = @_;
	# Top-level mail domains only — never alias / sub-server / child.
	return 0 if $aliasdom || $subdom || $parentdom;
	return 1;
}

sub feature_import {
	my ($dname, $user, $db) = @_;
	# Detect existing managed (or discoverable) install via CLI status.
	my $st = eval { status_for($dname) };
	return 0 unless $st;
	my $ver = $st->{'snappymail'} // '-';
	return ($ver ne '-' && $ver ne '') ? 1 : 0;
}

sub feature_setup {
	my ($d) = @_;
	&$virtual_server::first_print($text{'feat_setup'} || 'Installing SnappyMail…');
	if ($d->{'parent'} || $d->{'alias'} || $d->{'subdom'}) {
		&$virtual_server::second_print(
			$text{'feat_esuitable'} || 'Only top-level mail domains are supported');
		return 0;
	}
	my ($ex, $out) = run_cli('install', $d->{'dom'});
	if ($ex) {
		&$virtual_server::second_print(
			($text{'feat_efail'} || 'Failed').": $out");
		return 0;
	}
	&$virtual_server::second_print($virtual_server::text{'setup_done'} || '… done');
	return 1;
}

sub feature_delete {
	my ($d) = @_;
	return 1 if $d->{'parent'} || $d->{'alias'} || $d->{'subdom'};
	&$virtual_server::first_print($text{'feat_delete'} || 'Removing SnappyMail…');
	my @args = ('remove', $d->{'dom'}, '--yes');
	if ($config{'remove_subserver'} || $config{'remove_subserver_on_feature_delete'}) {
		push(@args, '--remove-subserver');
	}
	my ($ex, $out) = run_cli(@args);
	if ($ex) {
		&$virtual_server::second_print(
			($text{'feat_efail'} || 'Failed').": $out");
		return 0;
	}
	&$virtual_server::second_print($virtual_server::text{'setup_done'} || '… done');
	return 1;
}

sub feature_disable {
	my ($d) = @_;
	# Keep files; just mark as disabled at Virtualmin feature level.
	&$virtual_server::first_print($text{'feat_disable'} || 'SnappyMail left installed (disable only)');
	&$virtual_server::second_print($virtual_server::text{'setup_done'} || '… done');
	return 1;
}

sub feature_enable {
	my ($d) = @_;
	&$virtual_server::first_print($text{'feat_enable'} || 'Re-checking SnappyMail…');
	my ($ex, $out) = run_cli('repair', $d->{'dom'});
	&$virtual_server::second_print($ex
		? (($text{'feat_efail'} || 'Failed').": $out")
		: ($virtual_server::text{'setup_done'} || '… done'));
	return !$ex;
}

sub feature_webmin {
	my ($d, $all) = @_;
	my @doms = map { $_->{'dom'} }
		grep { $_->{$module_name} } @{$all || []};
	if (@doms) {
		return ([ $module_name, {
			'domains' => join(' ', @doms),
			'noconfig' => 1,
		} ]);
	}
	return ();
}

sub feature_modules {
	return ([ $module_name, $text{'feat_module'} || 'SnappyMail manager' ]);
}

sub feature_links {
	my ($d) = @_;
	return () if $d->{'parent'} || $d->{'alias'} || $d->{'subdom'};
	return ({
		'mod'  => $module_name,
		'desc' => $text{'links_manage'} || 'Manage SnappyMail',
		'page' => 'domain.cgi?dom='.&urlize($d->{'dom'}),
		'cat'  => 'services',
	});
}

sub feature_validate {
	my ($d) = @_;
	return undef if $d->{'parent'};
	my ($ex, $out) = run_cli('diagnose', $d->{'dom'});
	# diagnose exits 1 when checks fail — surface a short hint, not full dump
	if ($ex) {
		my $hint = $out // '';
		$hint =~ s/\s+/ /g;
		$hint = substr($hint, 0, 200) if length($hint) > 200;
		return $hint || ($text{'feat_evalidate'} || 'SnappyMail diagnose reported problems');
	}
	return undef;
}

1;
