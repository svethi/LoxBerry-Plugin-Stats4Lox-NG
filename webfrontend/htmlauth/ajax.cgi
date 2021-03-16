#!/usr/bin/perl
use warnings;
use strict;
use LoxBerry::System;
use LoxBerry::Log;
use CGI;
use JSON;
use FindBin qw($Bin);
use lib "$Bin/../../../../bin/plugins/stats4lox-ng/libs/";
use Globals;

my $error;
my $response;
my $cgi = CGI->new;
my $q = $cgi->Vars;

my $log = LoxBerry::Log->new (
    name => 'AJAX',
	stderr => 1,
	loglevel => 7
);


if( $q->{action} eq "getloxplan" ) {
	require Loxone::GetLoxplan;
	require Loxone::ParseXML;
	
	my %miniservers = LoxBerry::System::get_miniservers();
	
	if( ! defined $miniservers{$q->{msno}} ) {
		$error = "Miniserver not defined";
	}
	else {
		my $msno = $q->{msno};
		my $Loxplanfile = "$s4ltmp/s4l_loxplan_ms$msno.Loxone";		
		my $loxplanjson = "$loxplanjsondir/ms".$msno.".json";
		my $remoteTimestamp;
		eval {
			$remoteTimestamp = Loxone::GetLoxplan::checkLoxplanUpdate( $msno, $loxplanjson );
		};
		if( $@ or $remoteTimestamp ne "" ) {
			print STDERR "Loxplan file not up-to-date. Fetching from Miniserver\n";
			Loxone::GetLoxplan::getLoxplan( 
				ms => $msno, 
				log => $log 
			);
			
			if( -e $Loxplanfile ) {
				print STDERR "Loxplan for MS$msno found, parsing now...\n";
				my $loxplan = Loxone::ParseXML::loxplan2json( 
					filename => $Loxplanfile,
					output => $loxplanjson,
					log => $log,
					remoteTimestamp => $remoteTimestamp
				);
			}
			
		} else {
			print STDERR "Loxplan file is up-to-date. Using local copy\n";
		}
		
		if( -e $loxplanjson) { 
			$response = LoxBerry::System::read_file($loxplanjson);
		} else {
			$response = '{ "error":"Could not fetch Loxone Config of MS No. '.$msno.'"}';
		}
	
	}
	
}

if( $q->{action} eq "getstatsconfig" ) {
	if ( -e $statsconfig ) {
		$response = LoxBerry::System::read_file($statsconfig);
	}
	else {
		$response = "{ }";
	}
}

if( $q->{action} eq "updatestat" ) {
	require LoxBerry::JSON;
	my $jsonobjcfg = LoxBerry::JSON->new();
	my $cfg = $jsonobjcfg->open(filename => $statsconfig);
	my @searchresult = $jsonobjcfg->find( $cfg->{loxone}, "\$_->{uuid} eq \"".$q->{uuid}."\"" );
	my $elemKey = $searchresult[0];
	my $element = $cfg->{loxone}[$elemKey];
	print STDERR "Name: " . $element->{name} . "\n";
	use Data::Dumper;
	#print STDERR "Dump:\n" . Dumper(\@elements) . "\n";
	$response = "{ }";
}
	
	


if( defined $response ) {
	print "Status: 200 OK\r\n";
	print "Content-type: application/json\r\n\r\n";
	print $response;
}
elsif ( $error ne "" ) {
	print "Status: 500 Internal Server Error\r\n";
	print "Content-type: application/json\r\n\r\n";
	print to_json( { error => $error } );
}
else {
	print "Status: 501 Not implemented\r\n";
	print "Content-type: application/json\r\n\r\n";
	$error = "Action ".$q->{action}." unknown";
	print to_json( { error => $error } );
}
