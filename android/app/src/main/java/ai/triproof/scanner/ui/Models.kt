package ai.triproof.scanner.ui

import ai.triproof.scanner.R
import ai.triproof.scanner.ScannerApp
import ai.triproof.scanner.crypto.*
import ai.triproof.scanner.data.local.*
import ai.triproof.scanner.scanner.*
import android.app.Application
import androidx.lifecycle.*
import java.util.UUID
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.*

open class GraphModel(app: Application) : AndroidViewModel(app) {
    val scanner = app as ScannerApp
    protected val graph = scanner.graph
    protected val sharing = SharingStarted.WhileSubscribed(5_000)
    internal val messages =
        kotlinx.coroutines.channels.Channel<Int>(kotlinx.coroutines.channels.Channel.BUFFERED)

    protected fun launch(block: suspend () -> Unit) =
        viewModelScope.launch {
            try {
                block()
            } catch (e: CancellationException) {
                throw e
            } catch (_: java.security.GeneralSecurityException) {
                messages.send(R.string.key_error)
            } catch (_: java.io.IOException) {
                messages.send(R.string.network_error)
            } catch (_: Exception) {
                messages.send(R.string.operation_failed)
            }
        }
}

class LoginModel(app: Application) : GraphModel(app) {
    val busy = MutableStateFlow(false)
    val ready = MutableStateFlow(false)

    fun resumeSession() = launch { if (graph.await().keys.entered) ready.value = true }

    fun submit(email: String?, password: String = "") {
        if (busy.value) return
        busy.value = true
        launch {
            try {
                withContext(Dispatchers.IO) {
                    val g = graph.await()
                    if (email != null) g.repository.login(email, password)
                    else g.keys.entered = true
                }
                ready.value = true
            } finally {
                busy.value = false
            }
        }
    }
}

data class Connection(val connected: Boolean, val hasToken: Boolean, val synced: Boolean)

data class Banner(
    val id: Long?,
    val outer: String?,
    val status: String,
    val en: String?,
    val bn: String?,
)

class MainModel(app: Application) : GraphModel(app) {
    val connection =
        flow {
                val g = graph.await()
                emitAll(
                    combine(
                        scanner.online,
                        g.keys.token,
                        g.keys.lastSync,
                        g.db.history().pendingCount(),
                    ) { network, token, time, pending ->
                        Connection(
                            network,
                            token != null,
                            time != 0L && pending == 0 && token != null,
                        )
                    }
                )
            }
            .stateIn(viewModelScope, sharing, Connection(false, false, false))
    val unread = flow { emitAll(graph.await().db.inbox().unread()) }
    val banner = flow {
        val db = graph.await().db
        emitAll(
            combine(db.inbox().banner(), db.history().banner()) { n, row ->
                if (n != null)
                    Banner(
                        n.id,
                        n.outerSerial,
                        if (n.severity == "critical_clone") "retroactive_clone_alert"
                        else "retroactive_recall_alert",
                        n.messageEn,
                        n.messageBn,
                    )
                else
                    row?.let {
                        Banner(
                            null,
                            it.outerSerial,
                            it.cloudStatus,
                            it.feedbackMessageEn,
                            it.feedbackMessageBn,
                        )
                    }
            }
        )
    }

    fun dismiss(row: Banner) = launch {
        val db = graph.await().db
        row.id?.let { db.inbox().dismiss(it) }
        row.outer?.let { db.history().dismiss(it, row.status) }
    }

    fun foreground() = launch {
        withContext(Dispatchers.IO) { graph.await().keys.advance(System.currentTimeMillis()) }
    }
}

data class Capture(
    val step: Step = Step.OUTER,
    val outer: String = "",
    val ticket: String? = null,
    val mode: ScanMode = ScanMode.CONSUMER,
    val session: String = UUID.randomUUID().toString(),
    val resultId: Long = 0L,
)

class ScannerModel(app: Application, private val state: SavedStateHandle) : GraphModel(app) {
    private val _capture =
        MutableStateFlow(
            Capture(
                Step.valueOf(state["step"] ?: "OUTER").let {
                    if (it == Step.BUSY) Step.INNER else it
                },
                state["outer"] ?: "",
                state["ticket"],
                ScanMode.valueOf(state["mode"] ?: "CONSUMER"),
                state["session"] ?: UUID.randomUUID().toString(),
                state["result"] ?: 0L,
            )
        )
    val capture = _capture.asStateFlow()
    private var location: Pair<Fix, Long>? = null

    fun fix(fix: Fix, time: Long, elapsed: Long) {
        location = fix to elapsed
        launch { withContext(Dispatchers.IO) { graph.await().keys.advance(time) } }
    }

    private fun set(c: Capture) {
        _capture.value = c
        state["step"] = c.step.name
        state["outer"] = c.outer
        state["ticket"] = c.ticket
        state["mode"] = c.mode.name
        state["session"] = c.session
        state["result"] = c.resultId
    }

    fun reset() {
        val c = capture.value
        if (c.step != Step.BUSY) set(Capture(mode = c.mode, session = c.session))
    }

    fun startSession(mode: ScanMode) {
        if (capture.value.step != Step.BUSY) set(Capture(mode = mode))
    }

    fun seal() {
        val c = capture.value
        if (c.step == Step.SEAL) set(c.copy(step = Step.INNER))
    }

    fun accept(expected: Step, candidate: Candidate) {
        val c = capture.value
        if (c.step != expected) return
        when (expected) {
            Step.OUTER ->
                set(c.copy(step = Step.SEAL, outer = candidate.text, ticket = candidate.ticket))
            Step.INNER -> {
                set(c.copy(step = Step.BUSY))
                launch {
                    try {
                        val gps =
                            location
                                ?.takeIf {
                                    android.os.SystemClock.elapsedRealtime() - it.second in
                                        0..300_000L
                                }
                                ?.first
                        val row =
                            graph
                                .await()
                                .engine
                                .verify(c.outer, candidate.text, c.mode, c.session, gps, c.ticket)
                        set(c.copy(step = Step.RESULT, resultId = row.id))
                    } catch (e: Exception) {
                        set(c)
                        throw e
                    }
                }
            }
            else -> Unit
        }
    }

    fun stopLocation() {
        location = null
    }
}

@OptIn(ExperimentalCoroutinesApi::class)
class HistoryModel(app: Application) : GraphModel(app) {
    val query = MutableStateFlow("")
    val filter = MutableStateFlow("all")
    private val limit = MutableStateFlow(100)

    private data class Search(val q: String, val f: String, val n: Int)

    val rows =
        combine(query, filter, limit, ::Search)
            .flatMapLatest { graph.await().db.history().search(it.q, it.f, it.n) }
            .stateIn(viewModelScope, sharing, emptyList())

    fun more() {
        if (rows.value.size >= limit.value) limit.value += 100
    }
}

class InboxModel(app: Application) : GraphModel(app) {
    val rows = flow { emitAll(graph.await().db.inbox().all()) }

    fun markAll() = launch { graph.await().repository.markRead() }
}
