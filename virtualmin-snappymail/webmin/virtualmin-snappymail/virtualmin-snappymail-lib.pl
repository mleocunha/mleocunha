#!/usr/bin/perl
# Shared library for the SnappyMail Virtualmin Webmin module.
# All mutating work is delegated to the Python CLI (single source of truth).

use strict;
use warnings;
use WebminCore;

our (%config, %text, %access, $module_name, $module_config_directory);
our $module_root_directory;

&init_config();
&foreign_require("virtual-server");

# ---------------------------------------------------------------------------
# CLI helpers
# ---------------------------------------------------------------------------

sub get_cli {
	my $cli = $config{'cli_path'} || '/usr/sbin/virtualmin-snappymail';
	if (!-x $cli) {
		foreach my $c (
			'/usr/sbin/virtualmin-snappymail',
			'/usr/local/bin/virtualmin-snappymail',
			'virtualmin-snappymail',
		) {
			next unless $c;
			my $path = $c =~ m{/} ? $c : &has_command($c);
			if ($path && -x $path) {
				$cli = $path;
				last;
			}
		}
	}
	return $cli;
}

sub cli_available {
	my $cli = get_cli();
	return -x $cli ? undef : &text('err_nocli', $cli || 'virtualmin-snappymail');
}

sub run_cli {
	my (@args) = @_;
	my $cli = get_cli();
	-x $cli || return (1, "CLI not found: $cli");
	my $cmd = join(' ', map { quotemeta($_) } ($cli, @args));
	my $out = &backquote_command("$cmd 2>&1");
	my $ex = $?;
	# bash/perl exit codes are shifted
	$ex = $ex >> 8 if $ex > 255;
	return ($ex, $out);
}

sub run_cli_json {
	my (@args) = @_;
	my ($ex, $out) = run_cli('--json', @args);
	return ($ex, $out, undef) if $ex;
	my $data = decode_cli_json($out);
	if (!defined $data) {
		return (1, $out, undef);
	}
	return (0, $out, $data);
}

sub decode_cli_json {
	my ($raw) = @_;
	$raw = '' unless defined $raw;
	# Strip leading non-JSON noise (warnings).
	if ($raw =~ /(\{|\[)/s) {
		$raw = substr($raw, $-[0]);
	}
	if (defined &convert_from_json) {
		my $data;
		eval { $data = &convert_from_json($raw); };
		return $data if !$@ && defined $data;
	}
	my $data;
	eval {
		require JSON::PP;
		$data = JSON::PP->new->utf8->decode($raw);
	};
	return $@ ? undef : $data;
}

# ---------------------------------------------------------------------------
# ACL / domain access
# ---------------------------------------------------------------------------

sub can_domain {
	my ($dom) = @_;
	$dom = lc($dom // '');
	return 0 unless $dom;
	my $acl = $access{'domains'};
	# Undefined / empty / "1" → all domains (root defaultacl)
	if (!defined $acl || $acl eq '' || $acl eq '1') {
		return 1;
	}
	# "0" → Virtualmin ownership only
	if ($acl eq '0') {
		my $d = &virtual_server::get_domain_by('dom', $dom);
		return $d && &virtual_server::can_edit_domain($d) ? 1 : 0;
	}
	# Space-separated allow-list (feature_webmin style)
	foreach my $a (split(/\s+/, $acl)) {
		return 1 if lc($a) eq $dom;
	}
	return 0;
}

sub require_domain_access {
	my ($dom) = @_;
	&error(&text('err_domain_acl', $dom)) unless &can_domain($dom);
}

sub validate_domain_name {
	my ($dom) = @_;
	$dom = lc($dom // '');
	$dom =~ s/\.$//;
	$dom =~ /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/
		|| &error(&text('err_domain_invalid', $dom));
	return $dom;
}

# ---------------------------------------------------------------------------
# High-level CLI ops used by CGIs
# ---------------------------------------------------------------------------

sub list_status_rows {
	my ($ex, $out, $data) = run_cli_json('status', '--all');
	return () if $ex || !defined $data;
	return @$data if ref($data) eq 'ARRAY';
	return ();
}

sub list_mail_parents {
	my ($ex, $out) = run_cli('list-parents');
	return () if $ex;
	my @parents;
	foreach my $line (split(/\r?\n/, $out // '')) {
		$line =~ s/^\s+|\s+$//g;
		next unless $line;
		next if $line =~ /\s/;
		push(@parents, $line) if &can_domain($line);
	}
	return @parents;
}

sub status_for {
	my ($dom) = @_;
	my ($ex, $out, $data) = run_cli_json('status', $dom);
	return undef if $ex;
	if (ref($data) eq 'ARRAY') {
		return $data->[0];
	}
	return $data;
}

sub discover_hits {
	my ($ex, $out, $data) = run_cli_json('discover');
	return () if $ex || !defined $data;
	return @$data if ref($data) eq 'ARRAY';
	return ();
}

sub ui_ok_msg {
	my ($msg) = @_;
	print &ui_alert_box($msg, 'success'), "\n";
}

sub ui_err_msg {
	my ($msg) = @_;
	print &ui_alert_box($msg, 'danger'), "\n";
}

sub print_cli_result {
	my ($ex, $out, $ok_title) = @_;
	if ($ex) {
		&ui_err_msg($text{'err_cli'} || 'Command failed');
		print "<pre>", &html_escape($out // ''), "</pre>\n";
		return 0;
	}
	&ui_ok_msg($ok_title || ($text{'ok_done'} || 'Done'));
	print "<pre>", &html_escape($out // ''), "</pre>\n" if $out && $out =~ /\S/;
	return 1;
}

sub snappy_installed_for {
	my ($dom) = @_;
	my $st = status_for($dom);
	return 0 unless $st;
	my $ver = $st->{'snappymail'} // $st->{'version'} // '-';
	return 0 if $ver eq '-' || $ver eq '';
	return 1;
}

1;
