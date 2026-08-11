# virtualmin-snappymail — Virtualmin Manage Web Apps installer (hybrid)
#
# Mode A (subserver, default for top-level mail parents):
#   Provisions webmail.<domain> (web-only) via virtualmin-snappymail CLI.
# Mode B (path):
#   Installs SnappyMail under the domain public_html path (Roundcube-style).
#
# Installed to: /etc/webmin/virtual-server/scripts/snappymail.pl
# by bin/install-to-system.sh

use strict;
use warnings;

our (%text, %config);

sub script_snappymail_desc
{
return "SnappyMail";
}

sub script_snappymail_uses
{
return ("php");
}

sub script_snappymail_longdesc
{
return "Modern webmail client (SnappyMail). Can provision a dedicated ".
       "web-only sub-server webmail.<domain>, or install under a path on ".
       "this virtual server.";
}

sub script_snappymail_versions
{
# "latest" is resolved by the CLI / GitHub Releases at install time.
return ("latest", "2.38.2");
}

sub script_snappymail_version_desc
{
my ($ver) = @_;
return $ver eq "latest" ? "Latest stable (GitHub)" : $ver;
}

sub script_snappymail_category
{
return "Email";
}

sub script_snappymail_php_vers
{
return (8);
}

sub script_snappymail_php_fullver
{
return "8.0";
}

sub script_snappymail_php_modules
{
return ("xml", "dom", "iconv", "mbstring", "json", "curl", "zip");
}

sub script_snappymail_php_optional_modules
{
return ("openssl", "fileinfo", "intl", "gd");
}

sub script_snappymail_php_vars
{
return ([ 'memory_limit', '128M', '+' ],
	[ 'max_execution_time', 120, '+' ],
	[ 'file_uploads', 'On' ],
	[ 'upload_max_filesize', '25M', '+' ],
	[ 'post_max_size', '25M', '+' ]);
}

sub script_snappymail_release
{
return 1;
}

sub script_snappymail_site
{
return 'https://snappymail.eu/';
}

sub script_snappymail_gpl
{
return 1;
}

sub script_snappymail_depends
{
my ($d, $ver) = @_;
my $cli = &script_snappymail_cli();
-x $cli || return "virtualmin-snappymail CLI not found at $cli ".
	"(run: sudo bash bin/install-to-system.sh)";
&virtual_server::domain_has_website($d) ||
	return "SnappyMail requires a website on this virtual server";
return undef;
}

sub script_snappymail_cli
{
foreach my $c (
	$config{'cli_path'},
	'/usr/sbin/virtualmin-snappymail',
	'/usr/local/bin/virtualmin-snappymail',
	&has_command('virtualmin-snappymail'),
) {
	next unless $c;
	return $c if -x $c;
}
return '/usr/sbin/virtualmin-snappymail';
}

sub script_snappymail_run_cli
{
my (@args) = @_;
my $cli = &script_snappymail_cli();
my $cmd = join(' ', map { quotemeta($_) } ($cli, @args));
my $out = &backquote_command("$cmd 2>&1");
my $ex = $?;
$ex = $ex >> 8 if $ex > 255;
return ($ex, $out);
}

sub script_snappymail_decode_json
{
my ($raw) = @_;
$raw = '' unless defined $raw;
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

# script_snappymail_params(&domain, version, &upgrade-info)
sub script_snappymail_params
{
my ($d, $ver, $upgrade) = @_;
my $rv;
my $hdir = &public_html_dir($d, 1);
if ($upgrade) {
	my $mode = $upgrade->{'opts'}->{'mode'} || 'path';
	$rv .= &ui_table_row("Install mode",
		$mode eq 'subserver' ? "Web-only sub-server (webmail.$d->{'dom'})" : "Path under this domain");
	if ($mode eq 'path') {
		my $dir = $upgrade->{'opts'}->{'dir'} || '';
		$dir =~ s/^\Q$d->{'home'}\E\///;
		$rv .= &ui_table_row("Install directory", $dir);
	}
	else {
		$rv .= &ui_table_row("Webmail host",
			$upgrade->{'opts'}->{'webmail_domain'} || ("webmail.".$d->{'dom'}));
	}
	}
else {
	my $can_sub = (!$d->{'parent'} && !$d->{'alias'} && !$d->{'subdom'} && $d->{'mail'}) ? 1 : 0;
	my $default_mode = $can_sub ? 'subserver' : 'path';
	my @modes = ( [ 'path', "Under this domain's website (path)" ] );
	unshift(@modes, [ 'subserver', "Dedicated web-only sub-server webmail.$d->{'dom'} (recommended)" ])
		if $can_sub;
	$rv .= &ui_table_row("Install mode",
		&ui_radio("mode", $default_mode, \@modes));
	$rv .= &ui_table_row("Path under <tt>$hdir</tt> (path mode)",
		&ui_textbox("dir", "webmail", 30).
		"<br><font size=-1>Ignored when using the dedicated sub-server mode. ".
		"Use empty / top-level only if you intend to replace the site root.</font>");
	if ($can_sub) {
		$rv .= &ui_table_row("Also delete sub-server on uninstall",
			&ui_yesno_radio("remove_subserver", 0));
	}
}
return $rv;
}

# script_snappymail_parse(&domain, version, &in, &upgrade-info)
sub script_snappymail_parse
{
my ($d, $ver, $in, $upgrade) = @_;
if ($upgrade) {
	return $upgrade->{'opts'};
}
my $mode = $in->{'mode'} || 'path';
$mode = 'path' if $mode ne 'subserver' && $mode ne 'path';
if ($mode eq 'subserver') {
	$d->{'parent'} && return "Sub-server mode requires a top-level virtual server";
	$d->{'mail'} || return "Sub-server mode requires Mail enabled on the parent";
	return {
		'mode' => 'subserver',
		'dir' => $d->{'home'}."/public_html", # placeholder; install rewrites
		'path' => '/',
		'webmail_domain' => 'webmail.'.$d->{'dom'},
		'remove_subserver' => ($in->{'remove_subserver'} ? 1 : 0),
	};
}
my $hdir = &public_html_dir($d, 0);
my $subdir = defined($in->{'dir'}) ? $in->{'dir'} : 'webmail';
$subdir =~ s/^\s+|\s+$//g;
$subdir =~ s#^/+##;
$subdir =~ /\.\./ && return "Invalid installation directory";
my $dir = $subdir eq '' ? $hdir : "$hdir/$subdir";
my $path = $subdir eq '' ? '/' : "/$subdir";
return {
	'mode' => 'path',
	'dir' => $dir,
	'path' => $path,
	'install_path' => $subdir,
	'remove_subserver' => 0,
};
}

# script_snappymail_check(&domain, version, &opts, &upgrade-info)
sub script_snappymail_check
{
my ($d, $ver, $opts, $upgrade) = @_;
$opts->{'mode'} || return "Missing install mode";
if ($opts->{'mode'} eq 'subserver') {
	$d->{'mail'} || return "Sub-server mode requires Mail on this domain";
	$d->{'parent'} && return "Sub-server mode is only for top-level domains";
}
else {
	$opts->{'dir'} =~ /^\// || return "Missing or invalid install directory";
	if (-r "$opts->{'dir'}/index.php" && -d "$opts->{'dir'}/snappymail") {
		return "SnappyMail appears to be already installed in the selected directory";
	}
}
return undef;
}

sub script_snappymail_files
{
# CLI downloads the release; Virtualmin does not need a files list.
return ();
}

sub script_snappymail_commands
{
return ("tar", "gunzip");
}

# script_snappymail_install(&domain, version, &opts, &files, &upgrade)
sub script_snappymail_install
{
my ($d, $version, $opts, $files, $upgrade) = @_;
my $dom = $d->{'dom'};
my @args = ('--json', 'install', $dom);
if ($version && $version ne 'latest') {
	push(@args, '--snappy-version', $version);
}
if ($opts->{'mode'} eq 'subserver') {
	push(@args, '--mode', 'subserver');
}
else {
	my $ipath = $opts->{'install_path'};
	$ipath = 'webmail' unless defined $ipath;
	push(@args, '--mode', 'path', '--path', $ipath);
}
if ($upgrade) {
	# Prefer dedicated upgrade command
	@args = ('--json', 'upgrade', $dom);
	if ($version && $version ne 'latest') {
		push(@args, '--snappy-version', $version);
	}
}

my ($ex, $out) = &script_snappymail_run_cli(@args);
my $data = &script_snappymail_decode_json($out);
if ($ex) {
	return (0, "SnappyMail ".($upgrade ? "upgrade" : "install").
		" failed: ".&html_escape($out));
}

# Refresh opts so Virtualmin records the real directory / URL
if ($data && ref($data) eq 'HASH') {
	$opts->{'dir'} = $data->{'document_root'} if $data->{'document_root'};
	$opts->{'webmail_domain'} = $data->{'webmail_domain'} if $data->{'webmail_domain'};
	$opts->{'install_mode'} = $data->{'install_mode'} if $data->{'install_mode'};
	if (($data->{'install_mode'} || $opts->{'mode'}) eq 'subserver') {
		$opts->{'path'} = '/';
		$opts->{'mode'} = 'subserver';
	}
	else {
		$opts->{'mode'} = 'path';
		my $ip = $data->{'install_path'} // $opts->{'install_path'} // 'webmail';
		$opts->{'install_path'} = $ip;
		$opts->{'path'} = $ip eq '' ? '/' : "/$ip";
	}
}

my $url = ($data && $data->{'url'}) || &script_path_url($d, $opts);
my $ver_show = ($data && ($data->{'version'} || $data->{'version_after'})) || $version;
my $rp = $opts->{'dir'} || '';
$rp =~ s/^\Q$d->{'home'}\E\/// if $d->{'home'};
my $what = $upgrade ? 'upgrade' : 'installation';
my $detail = $opts->{'mode'} eq 'subserver'
	? "Dedicated web-only host ".$opts->{'webmail_domain'}
	: "Under $rp";
return (1,
	"SnappyMail $ver_show $what complete. Open <a target=_blank href='$url'>$url</a>.",
	$detail,
	$url);
}

# script_snappymail_uninstall(&domain, version, &opts)
sub script_snappymail_uninstall
{
my ($d, $version, $opts) = @_;
my @args = ('remove', $d->{'dom'}, '--yes');
if (($opts->{'mode'} || '') eq 'subserver' && $opts->{'remove_subserver'}) {
	push(@args, '--remove-subserver');
}
my ($ex, $out) = &script_snappymail_run_cli(@args);
return (0, "Remove failed: ".&html_escape($out)) if $ex;
return (1, "SnappyMail removed for $d->{'dom'}.");
}

sub script_snappymail_latest
{
my ($ver) = @_;
return ("https://github.com/the-djmaze/snappymail/releases",
	"snappymail-([0-9\\.]+)\\.tar\\.gz");
}

1;
