const DB_NAME = 'FundsFlowDB'
const DB_VERSION = 2
const TAGS = 'tags'
const TRANSACTIONS = 'transactions'

async function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION)

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onupgradeneeded = (event) => {
            const db = event.target.result

            if (!db.objectStoreNames.contains(TAGS)) {
                const store = db.createObjectStore(TAGS, { keyPath: 'id' })
            }

            if (!db.objectStoreNames.contains(TRANSACTIONS)) {
                const store = db.createObjectStore(TRANSACTIONS, { keyPath: 'id', autoIncrement: true })
                store.createIndex('date', 'at', { unique: false })
            }
        }
    })
}

export async function DB_Transactions_All() {
    const db = await openDB()

    return new Promise((resolve, reject) => {
        const tx = db.transaction(TRANSACTIONS, 'readonly')
        const store = tx.objectStore(TRANSACTIONS)
        const index = store.index('date')
        const request = index.getAll()

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        tx.oncomplete = () => {
            db.close()
        }
    })
}

export async function DB_Transactions_Add(transaction) {
    const db = await openDB()

    return new Promise((resolve, reject) => {
        const tx = db.transaction(TRANSACTIONS, 'readwrite')
        const store = tx.objectStore(TRANSACTIONS)

        const request = store.add(transaction)

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        tx.oncomplete = () => {
            db.close()
        }
    })
}

export async function DB_Transactions_Delete(id) {
    const db = await openDB()

    return new Promise((resolve, reject) => {
        const tx = db.transaction(TRANSACTIONS, 'readwrite')
        const store = tx.objectStore(TRANSACTIONS)

        const request = store.delete(id)

        request.onsuccess = (event) => {
            resolve(event.target.result)
        }

        request.onerror = (event) => {
            reject(new Error(event.target.errorCode))
        }

        tx.oncomplete = () => {
            db.close()
        }
    })
}
