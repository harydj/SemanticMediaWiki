<?php

namespace SMW\SQLStore;

use Hooks;
use Onoi\MessageReporter\MessageReporter;
use Onoi\MessageReporter\MessageReporterAwareTrait;
use Onoi\MessageReporter\MessageReporterFactory;
use SMW\CompatibilityMode;
use SMW\MediaWiki\Jobs\EntityIdDisposerJob;
use SMW\MediaWiki\Jobs\PropertyStatisticsRebuildJob;
use SMW\Options;
use SMW\Site;
use SMW\TypesRegistry;
use SMW\SetupFile;

/**
 * @private
 *
 * @license GNU GPL v2+
 * @since 2.5
 *
 * @author mwjames
 */
class Installer implements MessageReporter {

	use MessageReporterAwareTrait;

	/**
	 * Optimize option
	 */
	const OPT_TABLE_OPTIMIZE = 'installer.table.optimize';

	/**
	 * Job option
	 */
	const OPT_SUPPLEMENT_JOBS = 'installer.supplement.jobs';

	/**
	 * Import option
	 */
	const OPT_IMPORT = 'installer.import';

	/**
	 * Related to ExtensionSchemaUpdates
	 */
	const OPT_SCHEMA_UPDATE = 'installer.schema.update';

	/**
	 * `smw_hash` field population
	 */
	const POPULATE_HASH_FIELD_COMPLETE = 'populate.smw_hash_field_complete';

	/**
	 * @var TableSchemaManager
	 */
	private $tableSchemaManager;

	/**
	 * @var TableBuilder
	 */
	private $tableBuilder;

	/**
	 * @var TableIntegrityExaminer
	 */
	private $tableIntegrityExaminer;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @var SetupFile
	 */
	private $setupFile;

	/**
	 * @since 2.5
	 *
	 * @param TableSchemaManager $tableSchemaManager
	 * @param TableBuilder $tableBuilder
	 * @param TableIntegrityExaminer $tableIntegrityExaminer
	 */
	public function __construct( TableSchemaManager $tableSchemaManager, TableBuilder $tableBuilder, TableIntegrityExaminer $tableIntegrityExaminer ) {
		$this->tableSchemaManager = $tableSchemaManager;
		$this->tableBuilder = $tableBuilder;
		$this->tableIntegrityExaminer = $tableIntegrityExaminer;
		$this->options = new Options();
	}

	/**
	 * @since 3.0
	 *
	 * @param Options|array $options
	 */
	public function setOptions( $options ) {

		if ( !$options instanceof Options ) {
			$options = new Options( $options );
		}

		$this->options = $options;
	}

	/**
	 * @since 3.1
	 *
	 * @param SetupFile $setupFile
	 */
	public function setSetupFile( SetupFile $setupFile ) {
		$this->setupFile = $setupFile;
	}

	/**
	 * @since 2.5
	 *
	 * @param boolean $verbose
	 */
	public function install( $verbose = true ) {

		if ( $this->setupFile === null ) {
			$this->setupFile = new SetupFile();
		}

		// If for some reason the enableSemantics was not yet enabled
		// still allow to run the tables create in order for the
		// setup to be completed
		if ( CompatibilityMode::extensionNotEnabled() ) {
			CompatibilityMode::enableTemporaryCliUpdateMode();
		}

		$messageReporter = $this->newMessageReporter( $verbose );

		$messageReporter->reportMessage( "\nSelected storage engine: \"SMWSQLStore3\" (or an extension thereof)\n" );
		$messageReporter->reportMessage( "\nSetting up standard database configuration for SMW ...\n\n" );

		$this->setupFile->setMessageReporter( $messageReporter );
		$this->setupFile->setMaintenanceMode( $GLOBALS );

		$this->tableBuilder->setMessageReporter(
			$messageReporter
		);

		$this->tableIntegrityExaminer->setMessageReporter(
			$messageReporter
		);

		// #3559
		$auxiliaryIndices = [];

		Hooks::run(
			'SMW::SQLStore::Installer::AddAuxiliaryIndicesBeforeCreateTablesComplete',
			[
				&$auxiliaryIndices
			]
		);

		$this->tableSchemaManager->addAuxiliaryIndices(
			$auxiliaryIndices
		);

		foreach ( $this->tableSchemaManager->getTables() as $table ) {
			$this->tableBuilder->create( $table );
		}

		$this->tableIntegrityExaminer->checkOnPostCreation( $this->tableBuilder );

		$messageReporter->reportMessage( "\nDatabase initialized completed.\n" );

		$this->table_optimization( $messageReporter );
		$this->supplement_jobs( $messageReporter );

		if ( $this->setupFile === null ) {
			$this->setupFile = new SetupFile();
		}

		$this->setupFile->setUpgradeKey( $GLOBALS );

		Hooks::run(
			'SMW::SQLStore::Installer::AfterCreateTablesComplete',
			[
				$this->tableBuilder,
				$messageReporter,
				$this->options
			]
		);

		if ( $this->options->has( self::OPT_SCHEMA_UPDATE ) ) {
			$messageReporter->reportMessage( "\n" );
		}

		return true;
	}

	/**
	 * @since 2.5
	 *
	 * @param boolean $verbose
	 */
	public function uninstall( $verbose = true ) {

		$messageReporter = $this->newMessageReporter( $verbose );

		$messageReporter->reportMessage( "\nSelected storage engine: \"SMWSQLStore3\" (or an extension thereof)\n" );
		$messageReporter->reportMessage( "\nDeleting all database content and tables generated by SMW ...\n\n" );

		$this->tableBuilder->setMessageReporter(
			$messageReporter
		);

		foreach ( $this->tableSchemaManager->getTables() as $table ) {
			$this->tableBuilder->drop( $table );
		}

		$this->tableIntegrityExaminer->checkOnPostDestruction( $this->tableBuilder );

		Hooks::run(
			'SMW::SQLStore::Installer::AfterDropTablesComplete',
			[
				$this->tableBuilder,
				$messageReporter,
				$this->options
			]
		);

		$messageReporter->reportMessage( "\nStandard and auxiliary tables with all corresponding data\n" );
		$messageReporter->reportMessage( "have been removed successfully.\n" );

		return true;
	}

	/**
	 * @since 2.5
	 *
	 * @param string $message
	 */
	public function reportMessage( $message ) {
		ob_start();
		print $message;
		ob_flush();
		flush();
		ob_end_clean();
	}

	private function newMessageReporter( $verbose = true ) {

		if ( $this->messageReporter !== null && !$this->options->safeGet( self::OPT_SCHEMA_UPDATE, false ) ) {
			return $this->messageReporter;
		}

		$messageReporterFactory = MessageReporterFactory::getInstance();

		if ( !$verbose ) {
			$messageReporter = $messageReporterFactory->newNullMessageReporter();
		} else {
			$messageReporter = $messageReporterFactory->newObservableMessageReporter();
			$messageReporter->registerReporterCallback( [ $this, 'reportMessage' ] );
		}

		return $messageReporter;
	}

	private function table_optimization( $messageReporter ) {

		if ( !$this->options->safeGet( self::OPT_TABLE_OPTIMIZE, false ) ) {
			return $messageReporter->reportMessage( "\nSkipping the table optimization.\n" );
		}

		$messageReporter->reportMessage( "\nRunning table optimization (this may take a moment) ...\n\n" );

		foreach ( $this->tableSchemaManager->getTables() as $table ) {
			$this->tableBuilder->optimize( $table );
		}

		$messageReporter->reportMessage( "\nOptimization completed.\n" );
	}

	private function supplement_jobs( $messageReporter ) {

		if ( !$this->options->safeGet( self::OPT_SUPPLEMENT_JOBS, false ) ) {
			return $messageReporter->reportMessage( "\nSkipping supplement job creation.\n" );
		}

		$messageReporter->reportMessage( "\nAdding property statistics rebuild job ...\n" );

		$title = \Title::newFromText( 'SMW\SQLStore\Installer' );

		$job = new PropertyStatisticsRebuildJob(
			$title,
			PropertyStatisticsRebuildJob::newRootJobParams( 'smw.propertyStatisticsRebuild', $title ) + [ 'waitOnCommandLine' => true ]
		);

		$job->insert();

		$messageReporter->reportMessage( "   ... done.\n" );
		$messageReporter->reportMessage( "\nAdding entity disposer job ...\n" );

		$job = new EntityIdDisposerJob(
			$title,
			EntityIdDisposerJob::newRootJobParams( 'smw.entityIdDisposer', $title ) + [ 'waitOnCommandLine' => true ]
		);

		$job->insert();

		$messageReporter->reportMessage( "   ... done.\n" );
	}

}
