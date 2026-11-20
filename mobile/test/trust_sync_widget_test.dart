import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:triproof_ai_scanner/core/l10n/app_strings.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/website_sync_card.dart';

void main() {
  for (final language in AppLanguage.values) {
    testWidgets('website sync card renders in ${language.name}', (
      tester,
    ) async {
      final s = AppStrings(language);
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: WebsiteSyncCard(
                strings: s,
                serverUrl: 'https://t.focusautomobiles.com',
                lastSyncedAt: null,
                trustedKeyCount: 1,
                isSyncing: false,
                message: null,
                onUpdate: (_) async {},
              ),
            ),
          ),
        ),
      );

      expect(find.byKey(const Key('website_sync_card')), findsOneWidget);
      expect(
        find.byKey(const Key('website_sync_server_url_field')),
        findsOneWidget,
      );
      expect(find.text(s.serverUrlLabel), findsOneWidget);
      expect(
        find.byKey(const Key('website_sync_update_button')),
        findsOneWidget,
      );
      expect(find.text(s.updateFromWebsiteButton), findsOneWidget);
      expect(find.text(s.notYetSynced), findsOneWidget);
    });
  }
}
