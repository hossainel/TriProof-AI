package ai.triproof.scanner.sync

import ai.triproof.scanner.ScannerApp
import android.content.Context
import androidx.work.*
import java.io.IOException
import java.util.concurrent.TimeUnit
import retrofit2.HttpException

class SyncScansWorker(context: Context, params: WorkerParameters) :
    CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val repo = (applicationContext as ScannerApp).graph.await().repository
        val token = repo.keys.token.value ?: return Result.success() // retained until next sign-in
        if (
            !inputData.getBoolean("force", true) &&
                System.currentTimeMillis() - repo.keys.lastSync.value in 0L..60_000L &&
                repo.db.history().pending(1).isEmpty() &&
                repo.db.inbox().unsentReads().isEmpty()
        )
            return Result.success()
        return try {
            repo.sync(token)
            Result.success()
        } catch (e: HttpException) {
            when (e.code()) {
                401 -> {
                    repo.keys.expire(token)
                    Result.success()
                }
                408,
                409,
                429,
                in 500..599 -> Result.retry()
                else -> Result.failure()
            }
        } catch (_: IOException) {
            Result.retry()
        } catch (_: IllegalArgumentException) {
            Result.failure()
        }
    }

    companion object {
        private val connected =
            Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

        fun enqueue(context: Context, force: Boolean = false) {
            WorkManager.getInstance(context)
                .enqueueUniqueWork(
                    "triproof-outbox",
                    ExistingWorkPolicy.APPEND_OR_REPLACE,
                    OneTimeWorkRequestBuilder<SyncScansWorker>()
                        .setInputData(workDataOf("force" to force))
                        .setConstraints(connected)
                        .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                        .build(),
                )
        }

        fun schedule(context: Context) {
            WorkManager.getInstance(context)
                .enqueueUniquePeriodicWork(
                    "triproof-inbox",
                    ExistingPeriodicWorkPolicy.KEEP,
                    PeriodicWorkRequestBuilder<SyncScansWorker>(6, TimeUnit.HOURS)
                        .setConstraints(connected)
                        .build(),
                )
        }
    }
}
