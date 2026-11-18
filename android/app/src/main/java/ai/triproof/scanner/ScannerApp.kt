package ai.triproof.scanner

import ai.triproof.scanner.crypto.*
import ai.triproof.scanner.data.ScannerRepository
import ai.triproof.scanner.data.local.TriProofDatabase
import ai.triproof.scanner.data.remote.TriProofApi
import ai.triproof.scanner.sync.SyncScansWorker
import ai.triproof.scanner.util.postAlert
import android.app.Application
import android.content.Context
import android.net.*
import androidx.room.Room
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

class Graph(context: Context) {
    val keys = KeystoreManager(context)
    val db = Room.databaseBuilder(context, TriProofDatabase::class.java, "triproof.db").build()
    private val enqueue = { SyncScansWorker.enqueue(context) }
    val engine = OfflineVerificationEngine(db, keys, enqueue)
    val repository = ScannerRepository(db, keys, TriProofApi.create(), context::postAlert, enqueue)
}

class ScannerApp : Application() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    lateinit var graph: Deferred<Graph>
        private set

    private val connected = MutableStateFlow(false)
    val online = connected.asStateFlow()

    override fun onCreate() {
        super.onCreate()
        graph =
            scope.async {
                Graph(this@ScannerApp).also { graph ->
                    val ledgerTime = graph.db.history().highWater() ?: 0L
                    graph.keys.advance(maxOf(ledgerTime, System.currentTimeMillis()))
                }
            }
        val cm = getSystemService(ConnectivityManager::class.java)
        fun update() {
            val available =
                cm.getNetworkCapabilities(cm.activeNetwork)
                    ?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true
            if (available && !connected.value) SyncScansWorker.enqueue(this, force = true)
            connected.value = available
        }
        cm.registerDefaultNetworkCallback(
            object : ConnectivityManager.NetworkCallback() {
                override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) =
                    update()

                override fun onLost(network: Network) = update()
            }
        )
        update()
        SyncScansWorker.schedule(this)
    }
}
